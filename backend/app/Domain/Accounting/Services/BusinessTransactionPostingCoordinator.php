<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domain\Accounting\Exceptions\InvalidWorkflowStateException;
use App\Domain\Accounting\Exceptions\SegregationOfDutiesException;
use App\Domain\Accounting\Exceptions\UnpairedTransferException;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntrySource;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BusinessTransactionPostingCoordinator
{
    public function __construct(
        protected JournalPostingService $journalPostingService
    ) {}

    /**
     * Approves and posts a business transaction's draft journal entry to the general ledger.
     * Enforces pessimistic row-locking, tenant isolation, human SoD (Maker != Checker),
     * and idempotent HTTP 200 contract.
     *
     * @return array{
     *     success: bool,
     *     is_duplicate: bool,
     *     message: string,
     *     data: array{
     *         business_transaction_id: int,
     *         journal_entry_id: int|null,
     *         entry_number: string|null,
     *         posted_at: string|null
     *     },
     *     transaction: BusinessTransaction,
     *     journal_entry: JournalEntry|null
     * }
     *
     * @throws CrossTenantViolationException
     * @throws AuthorizationException
     * @throws InvalidWorkflowStateException
     * @throws SegregationOfDutiesException
     */
    public function approveAndPost(BusinessTransaction|int $transaction, User $approver): array
    {
        return DB::transaction(function () use ($transaction, $approver) {
            $txId = $transaction instanceof BusinessTransaction ? $transaction->id : (int) $transaction;

            $tx = BusinessTransaction::withoutGlobalScopes()
                ->where('id', $txId)
                ->lockForUpdate()
                ->firstOrFail();

            $orgId = (int) $tx->organization_id;

            // Tenant Isolation Guard
            if (TenantContext::hasTenant() && (int) TenantContext::getTenantId() !== $orgId) {
                throw new CrossTenantViolationException(
                    "BusinessTransaction {$tx->transaction_number} belongs to organization {$orgId}, which does not match active tenant."
                );
            }

            // RBAC Guard: Approver must possess both journal.approve and journal.post
            if (! $this->hasPermissions($approver, ['journal.approve', 'journal.post'])) {
                throw new AuthorizationException(
                    "User #{$approver->id} does not have required permissions (journal.approve and journal.post) to approve and post transactions."
                );
            }

            // Idempotency Guard: If already posted, return HTTP 200 payload without duplicate execution
            if ($tx->isPosted()) {
                $postedJournal = $tx->postedJournalEntry
                    ?? JournalEntry::withoutGlobalScopes()->find($tx->posted_journal_entry_id);

                return [
                    'success' => true,
                    'is_duplicate' => true,
                    'message' => 'Transaksi ini telah diposting sebelumnya ke buku besar.',
                    'data' => [
                        'business_transaction_id' => $tx->id,
                        'journal_entry_id' => $postedJournal?->id,
                        'entry_number' => $postedJournal?->entry_number,
                        'posted_at' => $tx->posted_at?->toISOString() ?? $postedJournal?->posted_at?->toISOString(),
                    ],
                    'transaction' => $tx,
                    'journal_entry' => $postedJournal,
                ];
            }

            // Workflow State Guard
            if ($tx->status === 'rejected') {
                throw new InvalidWorkflowStateException(
                    "Cannot approve rejected transaction {$tx->transaction_number}."
                );
            }

            // Period Lock Guard
            if ($tx->is_period_locked) {
                throw new ClosedFiscalPeriodException(
                    $tx->transaction_date ? (string) $tx->transaction_date : now()->format('Y-m-d'),
                    'locked',
                    "Business transaction {$tx->transaction_number} is locked by period close (is_period_locked = true)."
                );
            }

            // Bank Transfer Guard: Must be PAIRED
            if ($tx->isTransfer() && ! $tx->isTransferPaired()) {
                throw new UnpairedTransferException(
                    $tx->id,
                    (string) ($tx->transfer_pairing_status ?? 'UNPAIRED'),
                    "Internal bank transfer transaction {$tx->transaction_number} must be PAIRED before approval and posting."
                );
            }

            // Locate Draft Journal Entry
            // 1. Primary Canonical Source Lookup (journal_entry_sources)
            $source = JournalEntrySource::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('source_type', JournalEntrySource::TYPE_BUSINESS_TRANSACTION)
                ->where('source_id', $tx->id)
                ->first();

            $draft = null;
            if ($source) {
                $draft = JournalEntry::withoutGlobalScopes()
                    ->where('id', $source->journal_entry_id)
                    ->lockForUpdate()
                    ->first();
            }

            // 2. Direct FK Fallback
            if (! $draft) {
                $draft = JournalEntry::withoutGlobalScopes()
                    ->where('organization_id', $orgId)
                    ->where('business_transaction_id', $tx->id)
                    ->where('status', JournalEntry::STATUS_DRAFT)
                    ->lockForUpdate()
                    ->first();
            }

            if (! $draft) {
                throw new InvalidWorkflowStateException(
                    "Business transaction {$tx->transaction_number} does not have an active journal draft to approve."
                );
            }

            // If draft is already posted (state divergence recovery)
            if ($draft->isPosted()) {
                $tx->status = 'posted';
                $tx->posted_journal_entry_id = $draft->id;
                $tx->posted_at = $draft->posted_at;
                $tx->posted_by = $draft->posted_by;
                $tx->save();

                return [
                    'success' => true,
                    'is_duplicate' => true,
                    'message' => 'Transaksi ini telah diposting sebelumnya ke buku besar.',
                    'data' => [
                        'business_transaction_id' => $tx->id,
                        'journal_entry_id' => $draft->id,
                        'entry_number' => $draft->entry_number,
                        'posted_at' => $draft->posted_at?->toISOString(),
                    ],
                    'transaction' => $tx,
                    'journal_entry' => $draft,
                ];
            }

            // Segregation of Duties Enforcement (Interactive Human Maker != Human Checker)
            if ((int) $draft->created_by === (int) $approver->id) {
                throw new SegregationOfDutiesException($approver->id, $draft->entry_number);
            }

            // Maintain bidirectional pointer on draft while still in DRAFT status
            if ($draft->business_transaction_id === null) {
                $draft->business_transaction_id = $tx->id;
                $draft->save();
            }

            // Delegate to Phase 3 Single Accounting Core
            $postedEntry = $this->journalPostingService->post($draft, $approver);

            // Update BusinessTransaction Status & Denormalized Pointer
            $tx->status = 'posted';
            $tx->posted_journal_entry_id = $postedEntry->id;
            $tx->posted_at = $postedEntry->posted_at ?? now();
            $tx->posted_by = $approver->id;
            $tx->posting_error = null;
            $tx->save();

            // Synchronize paired transfer twin if applicable
            if ($tx->isTransfer() && $tx->paired_business_transaction_id) {
                $twin = BusinessTransaction::withoutGlobalScopes()->find($tx->paired_business_transaction_id);
                if ($twin && ! $twin->isPosted()) {
                    $twin->status = 'posted';
                    $twin->posted_journal_entry_id = $postedEntry->id;
                    $twin->posted_at = $tx->posted_at;
                    $twin->posted_by = $approver->id;
                    $twin->posting_error = null;
                    $twin->save();
                }
            }

            // Audit Trail Logging: JOURNAL_POSTED_MANUAL
            AuditService::log(
                action: 'JOURNAL_POSTED_MANUAL',
                modelType: BusinessTransaction::class,
                modelId: $tx->id,
                oldValues: ['status' => 'draft', 'posted_journal_entry_id' => null],
                newValues: [
                    'status' => 'posted',
                    'posted_journal_entry_id' => $postedEntry->id,
                    'posted_by' => $approver->id,
                    'posted_at' => $postedEntry->posted_at?->toISOString() ?? now()->toISOString(),
                    'entry_number' => $postedEntry->entry_number,
                ],
                notes: "Business transaction {$tx->transaction_number} approved and posted manually by User #{$approver->id}",
                userId: $approver->id,
                organizationId: $orgId
            );

            return [
                'success' => true,
                'is_duplicate' => false,
                'message' => 'Transaksi berhasil disetujui dan diposting ke buku besar.',
                'data' => [
                    'business_transaction_id' => $tx->id,
                    'journal_entry_id' => $postedEntry->id,
                    'entry_number' => $postedEntry->entry_number,
                    'posted_at' => $postedEntry->posted_at?->toISOString() ?? now()->toISOString(),
                ],
                'transaction' => $tx->fresh(['postedJournalEntry', 'bankTransaction']),
                'journal_entry' => $postedEntry,
            ];
        });
    }

    /**
     * Rejects a business transaction draft and routes it back to the review queue.
     * Cleans up any unposted journal drafts and records structured rejection metadata.
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     data: array{
     *         business_transaction_id: int,
     *         status: string,
     *         reason: string,
     *         rejected_by: int
     *     },
     *     transaction: BusinessTransaction
     * }
     *
     * @throws CrossTenantViolationException
     * @throws AuthorizationException
     * @throws InvalidWorkflowStateException
     */
    public function reject(BusinessTransaction|int $transaction, User $checker, string $reason): array
    {
        return DB::transaction(function () use ($transaction, $checker, $reason) {
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

            // RBAC Guard: Checker must have journal.review
            if (! $this->hasPermissions($checker, ['journal.review'])) {
                throw new AuthorizationException(
                    "User #{$checker->id} does not have required permission (journal.review) to reject transactions."
                );
            }

            if ($tx->isPosted()) {
                throw new InvalidWorkflowStateException(
                    "Cannot reject an already posted transaction {$tx->transaction_number}. Use reversal instead."
                );
            }

            if ($tx->status === 'rejected') {
                throw new InvalidWorkflowStateException(
                    "Transaction {$tx->transaction_number} is already rejected."
                );
            }
