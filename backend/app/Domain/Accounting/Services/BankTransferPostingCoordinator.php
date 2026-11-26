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
                notes: "Internal bank transfer paired: OUT #{$legOut->id} ({$legOut->transaction_number}) <-> IN #{$legIn->id} ({$legIn->transaction_number})",
                userId: $actor?->id,
                organizationId: $orgId
            );

            return TransferPairResult::paired(
                $legOut->id,
                $legIn->id,
                'Internal bank transfer successfully paired.',
                [
                    'source_transaction_id' => $legOut->id,
                    'destination_transaction_id' => $legIn->id,
                    'amount' => $amountOut->getAmount(),
                    'source_bank_account_id' => $bankAccOut,
                    'destination_bank_account_id' => $bankAccIn,
                ]
            );
        });
    }

    /**
     * Unpairs an unposted paired transfer transaction.
     *
     * @throws InvalidWorkflowStateException
     * @throws CrossTenantViolationException
     */
    public function unpair(BusinessTransaction|int $transaction, ?User $actor = null): bool
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

            // Actor RBAC Guard
            if ($actor !== null && ! $actor->isSuperAdmin()) {
                $hasPerm = $actor->can('journal.review') || $actor->can('journal.approve')
                    || (method_exists($actor, 'hasPermissionTo') && ($actor->hasPermissionTo('journal.review') || $actor->hasPermissionTo('journal.approve')));
                if (! $hasPerm) {
                    throw new AuthorizationException("User #{$actor->id} does not have required permission to unpair transfer transactions.");
                }
            }

            // Period Lock Guard
            if ($tx->is_period_locked) {
                throw new ClosedFiscalPeriodException(
                    $tx->transaction_date ? (string) $tx->transaction_date : now()->format('Y-m-d'),
                    'locked',
                    "Cannot unpair transaction {$tx->transaction_number}: period is locked."
                );
            }

            if ($tx->isPosted()) {
                throw new InvalidWorkflowStateException(
                    "Cannot unpair transaction {$tx->transaction_number}: already posted to general ledger. Use reversal instead."
                );
            }

            $pairedId = $tx->paired_business_transaction_id;

            // Reset primary transaction
            $tx->paired_business_transaction_id = null;
            $tx->transfer_pairing_status = TransferPairingStatus::PENDING_PAIRING->value;
            $tx->save();

            // Reset twin transaction if found
            if ($pairedId) {
                $paired = BusinessTransaction::withoutGlobalScopes()
                    ->where('id', $pairedId)
                    ->lockForUpdate()
                    ->first();

                if ($paired) {
                    if ((int) $paired->organization_id !== $orgId) {
                        throw new CrossTenantViolationException("Twin transaction #{$paired->id} belongs to different organization.");
                    }
                    if ($paired->is_period_locked) {
                        throw new ClosedFiscalPeriodException(
                            $paired->transaction_date ? (string) $paired->transaction_date : now()->format('Y-m-d'),
                            'locked',
                            "Cannot unpair transaction: twin transaction {$paired->transaction_number} is period locked."
                        );
                    }
                    if (! $paired->isPosted()) {
                        $paired->paired_business_transaction_id = null;
                        $paired->transfer_pairing_status = TransferPairingStatus::PENDING_PAIRING->value;
                        $paired->save();
                    }
                }
            }

            // Remove any unposted draft journal associated with either leg
            $this->cleanupTransferDrafts([$tx->id, $pairedId], $orgId);

            AuditService::log(
                action: 'TRANSFER_UNPAIRED',
                modelType: BusinessTransaction::class,
                modelId: $tx->id,
                notes: 'Internal bank transfer unpaired by User #'.($actor?->id ?? 'system')." for Transaction #{$tx->id}".($pairedId ? " and Twin #{$pairedId}" : ''),
                userId: $actor?->id,
                organizationId: $orgId
            );

            return true;
        });
    }

    /**
     * Creates a single balanced compound draft journal for the 2:1 internal bank transfer pair.
     *
     * @throws UnpairedTransferException
     * @throws CrossTenantViolationException
     */
    public function createTransferJournalDraft(BusinessTransaction|int $transaction, User|int $maker): JournalEntry
    {
        return DB::transaction(function () use ($transaction, $maker) {
            $txId = $transaction instanceof BusinessTransaction ? $transaction->id : (int) $transaction;
            $makerUser = $maker instanceof User ? $maker : User::withoutGlobalScopes()->findOrFail($maker);

            $tx = BusinessTransaction::withoutGlobalScopes()
                ->where('id', $txId)
                ->lockForUpdate()
                ->firstOrFail();

            $orgId = (int) $tx->organization_id;

            if (! $tx->isTransfer()) {
                throw new InvalidWorkflowStateException(
                    "Transaction {$tx->transaction_number} is of type '{$tx->transaction_type}', expected BANK_TRANSFER."
                );
            }

            if (! $tx->isTransferPaired() || ! $tx->paired_business_transaction_id) {
                throw new UnpairedTransferException(
                    $tx->id,
                    $tx->transfer_pairing_status,
                    "Transaction {$tx->transaction_number} must be PAIRED before generating a transfer journal draft."
                );
            }

            $twin = BusinessTransaction::withoutGlobalScopes()
                ->where('id', $tx->paired_business_transaction_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $twin->isTransferPaired() || (int) $twin->paired_business_transaction_id !== (int) $tx->id) {
                throw new UnpairedTransferException(
                    $tx->id,
                    $tx->transfer_pairing_status,
                    "Transfer pair integrity broken between #{$tx->id} and #{$twin->id}."
                );
            }

            // Identify Source Leg (OUT) and Destination Leg (IN)
            $legOut = $tx->direction === 'OUT' ? $tx : $twin;
            $legIn = $tx->direction === 'IN' ? $tx : $twin;

            // Period Lock Guard
            if ($legOut->is_period_locked || $legIn->is_period_locked) {
                throw new ClosedFiscalPeriodException(
                    $legOut->transaction_date ? (string) $legOut->transaction_date : now()->format('Y-m-d'),
                    'locked',
                    'Cannot generate transfer draft: one or both transactions are period locked.'
                );
            }

            // Check if draft already exists via canonical source-of-truth
            $existingSource = JournalEntrySource::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('source_type', JournalEntrySource::TYPE_BUSINESS_TRANSACTION)
                ->whereIn('source_id', [$legOut->id, $legIn->id])
                ->first();

            if ($existingSource) {
                $existingDraft = JournalEntry::withoutGlobalScopes()->find($existingSource->journal_entry_id);
                if ($existingDraft && $existingDraft->isDraft()) {
                    return $existingDraft->fresh(['lines', 'sources']);
                }
            }

            $sourceBank = $legOut->bankTransaction?->bankAccount
                ?? $legOut->bankTransaction?->bank_account_id;
            $destBank = $legIn->bankTransaction?->bankAccount
                ?? $legIn->bankTransaction?->bank_account_id;

            if (! $sourceBank || ! $destBank) {
                throw new InvalidBankTransferContextException(
                    "Source bank account or destination bank account could not be resolved for transfer pairing #{$legOut->id} <-> #{$legIn->id}."
                );
            }

            $sourceBankId = is_object($sourceBank) ? $sourceBank->id : (int) $sourceBank;
            $destBankId = is_object($destBank) ? $destBank->id : (int) $destBank;

            $transferContext = new BankTransferContext(
                sourceBankAccountId: $sourceBankId,
                destinationBankAccountId: $destBankId,
                sourceTransactionId: $legOut->id,
                destinationTransactionId: $legIn->id,
                amount: $legOut->amount
            );

            // Delegate draft creation to JournalDraftGenerator with compound sources
            $draft = $this->draftGenerator->generate(
                transaction: $legOut,
                maker: $makerUser,
                options: [
                    'category' => PostingCategory::BANK_TRANSFER,
                    'source_bank_account' => $sourceBank,
                    'destination_bank_account' => $destBank,
                    'transfer_context' => $transferContext,
                    'leg_role' => JournalEntrySource::ROLE_TRANSFER_SOURCE,
                    'additional_sources' => [
                        [
                            'source_id' => $legIn->id,
                            'business_transaction_id' => $legIn->id,
                            'leg_role' => JournalEntrySource::ROLE_TRANSFER_DESTINATION,
                        ],
                    ],
                ]
            );

            // Set backward-compatible pointers on both transactions
            $legOut->posted_journal_entry_id = $draft->id;
            $legOut->save();

            $legIn->posted_journal_entry_id = $draft->id;
            $legIn->save();

            return $draft->fresh(['lines', 'sources']);
        });
    }

    /**
     * Batch detects and pairs all pending bank transfer transactions in a tenant.
     *
     * @return array{total_checked: int, paired_count: int, ambiguous_count: int, pending_count: int}
     */
    public function batchDetectAndPair(int $organizationId, ?User $actor = null): array
    {
        $pendingTransfers = BusinessTransaction::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('transaction_type', 'BANK_TRANSFER')
            ->whereNotIn('status', ['posted', 'rejected', 'reversed'])
            ->where(function ($q) {
                $q->whereNull('transfer_pairing_status')
                    ->orWhereIn('transfer_pairing_status', [
                        TransferPairingStatus::PENDING_PAIRING->value,
                        TransferPairingStatus::NOT_APPLICABLE->value,
                    ]);
            })
            ->pluck('id')
            ->all();

        $stats = [
            'total_checked' => count($pendingTransfers),
            'paired_count' => 0,
            'ambiguous_count' => 0,
            'pending_count' => 0,
        ];

        foreach ($pendingTransfers as $txId) {
            // Check if already paired by an earlier iteration
            $fresh = BusinessTransaction::withoutGlobalScopes()->find($txId);
            if ($fresh && $fresh->isTransferPaired()) {
                continue;
            }

            try {
                $res = $this->detectAndPair($txId, $actor);

                if ($res->status === TransferPairingStatus::PAIRED) {
                    $stats['paired_count']++;
                } elseif ($res->status === TransferPairingStatus::PAIRING_AMBIGUOUS) {
                    $stats['ambiguous_count']++;
                } else {
                    $stats['pending_count']++;
                }
            } catch (\Throwable) {
                $stats['pending_count']++;
            }
        }

        return $stats;
    }

    /**
     * Returns a query builder for the Transfer Review Queue.
     */
    public function getTransferReviewQueueQuery(int $organizationId): Builder
    {
        return BusinessTransaction::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('transaction_type', 'BANK_TRANSFER')
            ->whereNotIn('status', ['posted', 'rejected', 'reversed'])
            ->whereIn('transfer_pairing_status', [
                TransferPairingStatus::PAIRING_AMBIGUOUS->value,
                TransferPairingStatus::PENDING_PAIRING->value,
            ])
            ->orderBy('transaction_date')
            ->orderBy('id');
    }

    /**
     * Attempts heuristic tie-breakers between candidate matches.
     *
     * @param  Collection<int, BusinessTransaction>  $candidates
     */
    protected function resolveTieBreaker(BusinessTransaction $tx, Collection $candidates): ?BusinessTransaction
    {
        $desc = mb_strtolower((string) ($tx->description ?? ''));

        // Check if description specifically mentions candidate bank account number or distinctive number token
        $scored = $candidates->filter(function (BusinessTransaction $candidate) use ($desc) {
            $bankAcc = $candidate->bankTransaction?->bankAccount;
            if (! $bankAcc) {
                return false;
            }

            $cleanAcc = preg_replace('/[^0-9]/', '', $bankAcc->account_number);
            if (strlen($cleanAcc) >= 5 && mb_stripos($desc, $cleanAcc) !== false) {
                return true;
            }

            // Check any 3+ digit token in description matching the account number or name
            preg_match_all('/\d{3,}/', $desc, $matches);
            if (! empty($matches[0])) {
                foreach ($matches[0] as $token) {
                    if (str_contains($bankAcc->account_number, $token) || str_contains(mb_strtolower($bankAcc->account_name), $token)) {
                        return true;
                    }
                }
            }

            return false;
        });

        if ($scored->count() === 1) {
            return $scored->first();
        }

        return null;
    }

    /**
     * Cleans up unposted draft journals and sources for specified transaction IDs.
     *
     * @param  array<int|null>  $transactionIds
     */
    protected function cleanupTransferDrafts(array $transactionIds, int $organizationId): void
    {
        $validIds = array_filter($transactionIds, fn ($id) => ! empty($id));
        if (empty($validIds)) {
            return;
        }

        $sources = JournalEntrySource::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('source_type', JournalEntrySource::TYPE_BUSINESS_TRANSACTION)
            ->whereIn('source_id', $validIds)
            ->get();

        foreach ($sources as $source) {
            $draft = JournalEntry::withoutGlobalScopes()->find($source->journal_entry_id);
            if ($draft && $draft->isDraft()) {
                $source->delete();
                $draft->lines()->delete();
                $draft->delete();
            }
        }
    }
}
