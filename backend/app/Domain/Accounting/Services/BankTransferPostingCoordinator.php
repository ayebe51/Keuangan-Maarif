<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DTOs\BankTransferContext;
use App\Domain\Accounting\DTOs\TransferPairResult;
use App\Domain\Accounting\Enums\PostingCategory;
use App\Domain\Accounting\Enums\TransferPairingStatus;
use App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domain\Accounting\Exceptions\InvalidTransferPairingException;
use App\Domain\Accounting\Exceptions\InvalidWorkflowStateException;
use App\Domain\Accounting\Exceptions\UnpairedTransferException;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntrySource;
use App\Domain\Accounting\ValueObjects\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BankTransferPostingCoordinator
{
    public function __construct(
        protected JournalDraftGenerator $draftGenerator
    ) {}

    /**
     * Deterministically detects and pairs an internal bank transfer with its opposite leg.
     */
    public function detectAndPair(BusinessTransaction|int $transaction, ?User $actor = null): TransferPairResult
    {
        return DB::transaction(function () use ($transaction, $actor) {
            $txId = $transaction instanceof BusinessTransaction ? $transaction->id : (int) $transaction;

            $tx = BusinessTransaction::withoutGlobalScopes()
                ->where('id', $txId)
                ->lockForUpdate()
                ->firstOrFail();

            $orgId = (int) $tx->organization_id;

            if (TenantContext::hasTenant() && (int) TenantContext::getTenantId() !== $orgId) {
                throw new CrossTenantViolationException(
                    "BusinessTransaction {$tx->transaction_number} belongs to organization {$orgId}, which does not match active tenant."
                );
            }

            if (! $tx->isTransfer()) {
                return TransferPairResult::notApplicable(
                    $tx->id,
                    "Transaction {$tx->transaction_number} is of type '{$tx->transaction_type}', not BANK_TRANSFER."
                );
            }

            // If already paired, verify pairing consistency
            if ($tx->isTransferPaired() && $tx->paired_business_transaction_id) {
                $paired = BusinessTransaction::withoutGlobalScopes()->find($tx->paired_business_transaction_id);
                if ($paired && (int) $paired->paired_business_transaction_id === (int) $tx->id) {
                    return TransferPairResult::paired(
                        $tx->direction === 'OUT' ? $tx->id : $paired->id,
                        $tx->direction === 'IN' ? $tx->id : $paired->id,
                        'Transaction is already paired.',
                        ['source_id' => $tx->id, 'paired_id' => $paired->id]
                    );
                }
            }

            if ($tx->isPosted()) {
                return TransferPairResult::failed(
                    $tx->id,
                    'ALREADY_POSTED',
                    "Transaction {$tx->transaction_number} is already posted and cannot change pairing."
                );
            }

            $oppDirection = ($tx->direction === 'OUT') ? 'IN' : 'OUT';

            $txDate = $tx->transaction_date instanceof Carbon
                ? $tx->transaction_date->format('Y-m-d')
                : (string) $tx->transaction_date;

            $startDate = Carbon::parse($txDate)->subDays(3)->format('Y-m-d');
            $endDate = Carbon::parse($txDate)->addDays(3)->format('Y-m-d');

            // Find unposted candidates matching opposite direction, exact amount, within 3 days
            $candidates = BusinessTransaction::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('id', '!=', $tx->id)
                ->where('transaction_type', 'BANK_TRANSFER')
                ->where('direction', $oppDirection)
                ->where('amount', $tx->amount)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->whereNotIn('status', ['posted', 'rejected', 'reversed'])
                ->where(function ($q) use ($tx) {
                    $q->whereNull('paired_business_transaction_id')
                        ->orWhere('paired_business_transaction_id', $tx->id);
                })
                ->lockForUpdate()
                ->get();

            // Filter out any candidates from the SAME bank account
            $txBankAccId = $tx->bankTransaction?->bank_account_id;
            /** @var Collection<int, BusinessTransaction> $validCandidates */
            $validCandidates = $candidates->filter(function (BusinessTransaction $candidate) use ($txBankAccId) {
                $candBankAccId = $candidate->bankTransaction?->bank_account_id;
                if ($txBankAccId && $candBankAccId && $txBankAccId === $candBankAccId) {
                    return false;
                }

                return true;
            })->values();

            $count = $validCandidates->count();

            // Scenario 1: Zero candidates found
            if ($count === 0) {
                $tx->transfer_pairing_status = TransferPairingStatus::PENDING_PAIRING->value;
                $tx->paired_business_transaction_id = null;
                $tx->save();

                return TransferPairResult::pending(
                    $tx->id,
                    'NO_OPPOSITE_LEG_FOUND',
                    "No matching opposite transfer leg found for amount {$tx->amount} within 3-day window."
                );
            }

            // Scenario 2: Exactly one candidate found -> Deterministic Pair!
            if ($count === 1) {
                /** @var BusinessTransaction $match */
                $match = $validCandidates->first();

                return $this->pair($tx, $match, $actor);
            }

            // Scenario 3: Multiple candidates found -> Ambiguity Check
            // Attempt tie breaker: check if account number is mentioned in description
            $resolvedCandidate = $this->resolveTieBreaker($tx, $validCandidates);

            if ($resolvedCandidate) {
                return $this->pair($tx, $resolvedCandidate, $actor);
            }

            // Unresolvable ambiguity -> Fail-Safe C-02: DO NOT AUTO-PAIR!
            $candidateIds = $validCandidates->pluck('id')->all();

            $tx->transfer_pairing_status = TransferPairingStatus::PAIRING_AMBIGUOUS->value;
            $tx->posting_error = [
                'error_code' => 'PAIRING_AMBIGUOUS',
                'candidate_ids' => $candidateIds,
                'message' => "Found {$count} identical transfer candidates matching amount {$tx->amount} between {$startDate} and {$endDate}. Manual review required.",
                'occurred_at' => now()->toISOString(),
            ];
            $tx->save();

            // Also flag candidates as ambiguous so other jobs don't pair them erroneously
            foreach ($validCandidates as $cand) {
                if ($cand->transfer_pairing_status !== TransferPairingStatus::PAIRING_AMBIGUOUS->value) {
                    $cand->transfer_pairing_status = TransferPairingStatus::PAIRING_AMBIGUOUS->value;
                    $cand->posting_error = [
                        'error_code' => 'PAIRING_AMBIGUOUS',
                        'candidate_ids' => array_merge([$tx->id], array_diff($candidateIds, [$cand->id])),
                        'message' => 'Multiple conflicting transfer matches detected.',
                        'occurred_at' => now()->toISOString(),
                    ];
                    $cand->save();
                }
            }

            AuditService::log(
                action: 'TRANSFER_PAIRING_AMBIGUOUS',
                modelType: BusinessTransaction::class,
                modelId: $tx->id,
                notes: "Transfer pairing ambiguous for {$tx->transaction_number}: candidates [".implode(', ', $candidateIds).']',
                userId: $actor?->id,
                organizationId: $orgId
            );

            return TransferPairResult::ambiguous(
                $tx->id,
                $candidateIds,
                "Found {$count} matching candidates. System safely routed to Transfer Review Queue."
            );
        });
    }

    /**
     * Explicitly/manually pairs two specific transfer transactions.
     *
     * @throws InvalidTransferPairingException
     * @throws CrossTenantViolationException
     */
    public function pair(
        BusinessTransaction|int $first,
        BusinessTransaction|int $second,
        ?User $actor = null
    ): TransferPairResult {
        return DB::transaction(function () use ($first, $second, $actor) {
            $firstId = $first instanceof BusinessTransaction ? $first->id : (int) $first;
            $secondId = $second instanceof BusinessTransaction ? $second->id : (int) $second;

            if ($firstId === $secondId) {
                throw new InvalidTransferPairingException($firstId, $secondId, 'Cannot pair a transaction to itself.');
            }

            // Lock both rows in ordered sequence to prevent deadlocks
            $ids = [$firstId, $secondId];
            sort($ids);

            $rows = BusinessTransaction::withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var BusinessTransaction|null $txA */
            $txA = $rows->get($firstId);
            /** @var BusinessTransaction|null $txB */
            $txB = $rows->get($secondId);

            if (! $txA || ! $txB) {
                throw new InvalidTransferPairingException($firstId, $secondId, 'One or both transactions do not exist.');
            }

            $orgId = (int) $txA->organization_id;

            // Tenant boundary validation
            if ((int) $txB->organization_id !== $orgId) {
                throw new CrossTenantViolationException(
                    "Cannot pair transactions across different organizations ({$txA->organization_id} vs {$txB->organization_id})."
                );
            }

            if (TenantContext::hasTenant() && (int) TenantContext::getTenantId() !== $orgId) {
                throw new CrossTenantViolationException(
                    "Transactions belong to organization {$orgId}, which does not match active tenant."
                );
            }

            // Actor RBAC Guard
            if ($actor !== null && ! $actor->isSuperAdmin()) {
                $hasPerm = $actor->can('journal.review') || $actor->can('journal.approve')
                    || (method_exists($actor, 'hasPermissionTo') && ($actor->hasPermissionTo('journal.review') || $actor->hasPermissionTo('journal.approve')));
                if (! $hasPerm) {
                    throw new AuthorizationException("User #{$actor->id} does not have required permission to pair transfer transactions.");
                }
            }

            // Type validation
            if (! $txA->isTransfer() || ! $txB->isTransfer()) {
                throw new InvalidTransferPairingException(
                    $firstId,
                    $secondId,
                    'Both transactions must be of type BANK_TRANSFER.'
                );
            }

            // Opposite direction validation
            if ($txA->direction === $txB->direction) {
                throw new InvalidTransferPairingException(
                    $firstId,
                    $secondId,
                    "Cannot pair two transactions with identical direction '{$txA->direction}'. One must be OUT and one must be IN."
                );
            }

            // Assign Source Leg (OUT) and Destination Leg (IN)
            $legOut = $txA->direction === 'OUT' ? $txA : $txB;
            $legIn = $txA->direction === 'IN' ? $txA : $txB;

            // Period Lock Guard
            if ($legOut->is_period_locked || $legIn->is_period_locked) {
                throw new ClosedFiscalPeriodException(
                    $legOut->transaction_date ? (string) $legOut->transaction_date : now()->format('Y-m-d'),
                    'locked',
                    'Cannot pair transfer transactions: one or both transactions are period locked.'
                );
            }

            // Bank Account validation
            $bankAccOut = $legOut->bankTransaction?->bank_account_id;
            $bankAccIn = $legIn->bankTransaction?->bank_account_id;

            if ($bankAccOut && $bankAccIn && $bankAccOut === $bankAccIn) {
                throw new InvalidTransferPairingException(
                    $firstId,
                    $secondId,
                    'Internal bank transfer cannot occur within the same bank account.'
                );
            }

            // Amount equality validation
            $amountOut = Money::of($legOut->amount);
            $amountIn = Money::of($legIn->amount);

            if (! $amountOut->equals($amountIn)) {
                throw new InvalidTransferPairingException(
                    $firstId,
                    $secondId,
                    "Transfer amounts do not match (OUT: {$amountOut->getAmount()}, IN: {$amountIn->getAmount()})."
                );
            }

            // Already Paired guard: Both legs must not be already paired
            if ($legOut->isTransferPaired() || $legIn->isTransferPaired()) {
                throw new InvalidTransferPairingException(
                    $firstId,
                    $secondId,
                    "Transaction is already paired (Leg OUT #{$legOut->id}: {$legOut->transfer_pairing_status}, Leg IN #{$legIn->id}: {$legIn->transfer_pairing_status}). Unpair before establishing a new pairing."
                );
            }

            // Posted / Reversed guard
            if ($legOut->isPosted() || $legIn->isPosted()) {
                throw new InvalidTransferPairingException(
                    $firstId,
                    $secondId,
                    'Cannot re-pair a transaction that has already been posted to the general ledger.'
                );
            }

            if ($legOut->isReversed() || $legIn->isReversed()) {
                throw new InvalidTransferPairingException(
                    $firstId,
                    $secondId,
                    'Cannot pair a reversed transaction.'
                );
            }

            // Establish Bidirectional 2:1 Pairing
            $legOut->paired_business_transaction_id = $legIn->id;
            $legOut->transfer_pairing_status = TransferPairingStatus::PAIRED->value;
            $legOut->posting_error = null;
            $legOut->save();

            $legIn->paired_business_transaction_id = $legOut->id;
            $legIn->transfer_pairing_status = TransferPairingStatus::PAIRED->value;
            $legIn->posting_error = null;
            $legIn->save();

            AuditService::log(
                action: $actor ? 'TRANSFER_PAIRED_MANUAL' : 'TRANSFER_PAIRED_AUTO',
                modelType: BusinessTransaction::class,
                modelId: $legOut->id,
