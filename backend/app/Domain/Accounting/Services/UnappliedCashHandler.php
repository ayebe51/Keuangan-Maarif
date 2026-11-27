<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\PostingCategory;
use App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domain\Accounting\Exceptions\InactiveAccountException;
use App\Domain\Accounting\Exceptions\InvalidWorkflowStateException;
use App\Domain\Accounting\Exceptions\NonPostableAccountException;
use App\Domain\Accounting\Exceptions\UnresolvedAccountMappingException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntrySource;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UnappliedCashHandler
{
    public function __construct(
        protected JournalDraftGenerator $draftGenerator,
        protected AccountMappingResolver $accountResolver
    ) {}

    /**
     * Identifies if a transaction represents unapplied cash / unallocated receipt.
     */
    public function identify(BusinessTransaction|BankTransaction $transaction): bool
    {
        if ($transaction instanceof BusinessTransaction) {
            if ($transaction->direction !== 'IN') {
                return false;
            }

            if ($transaction->transaction_type === 'UNAPPLIED_CASH') {
                return true;
            }

            // Check if classified as unapplied cash or if notes indicate overpayment
            if ($transaction->target_account_id) {
                $account = Account::withoutGlobalScopes()->find($transaction->target_account_id);
                if ($account && $account->code === '2200') {
                    return true;
                }
            }

            return false;
        }

        // BankTransaction detection
        if (! $transaction->isCashIn()) {
            return false;
        }

        $desc = mb_strtolower((string) ($transaction->description ?? ''));
        $keywords = ['titipan', 'unapplied', 'kelebihan', 'belum jelas', 'suspense'];
        foreach ($keywords as $kw) {
            if (mb_stripos($desc, $kw) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Creates a balanced draft journal isolating unapplied cash into liability account 2200.
     * Enforces P&L neutrality (Asset = Liability).
     *
     * @throws CrossTenantViolationException
     * @throws InvalidWorkflowStateException
     */
    public function createDraft(BusinessTransaction $transaction, User $maker): JournalEntry
    {
        return DB::transaction(function () use ($transaction, $maker) {
            $tx = BusinessTransaction::withoutGlobalScopes()
                ->where('id', $transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            $orgId = (int) $tx->organization_id;

            if (TenantContext::hasTenant() && (int) TenantContext::getTenantId() !== $orgId) {
                throw new CrossTenantViolationException(
                    "BusinessTransaction {$tx->transaction_number} belongs to organization {$orgId}, which does not match active tenant."
                );
            }

            // Period Lock Guard
            if ($tx->is_period_locked) {
                throw new ClosedFiscalPeriodException(
                    $tx->transaction_date ? (string) $tx->transaction_date : now()->format('Y-m-d'),
                    'locked',
                    "Cannot generate draft for unapplied cash: transaction {$tx->transaction_number} is period locked."
                );
            }

            if ($tx->direction !== 'IN') {
                throw new InvalidWorkflowStateException(
                    "Unapplied cash must be an incoming receipt (direction IN). Transaction {$tx->transaction_number} has direction '{$tx->direction}'."
                );
            }

            if ($tx->isPosted()) {
                throw new InvalidWorkflowStateException(
                    "Transaction {$tx->transaction_number} has already been posted to the general ledger."
                );
            }

            // Check existing draft
            $existingSource = JournalEntrySource::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('source_type', JournalEntrySource::TYPE_BUSINESS_TRANSACTION)
                ->where('source_id', $tx->id)
                ->first();

            if ($existingSource) {
                $existingDraft = JournalEntry::withoutGlobalScopes()->find($existingSource->journal_entry_id);
                if ($existingDraft && $existingDraft->isDraft()) {
                    return $existingDraft->fresh(['lines', 'sources']);
                }
            }

            // Resolve bank account
            $bankAcc = $tx->bankTransaction?->bankAccount
                ?? $tx->bankTransaction?->bank_account_id;

            // Generate balanced 2-line draft via JournalDraftGenerator:
            // Line 1: Debit Bank Account (11xx)
            // Line 2: Credit Unapplied Cash Liability (2200)
            $draft = $this->draftGenerator->generate(
                transaction: $tx,
                maker: $maker,
                options: [
                    'category' => PostingCategory::UNAPPLIED_CASH,
                    'bank_account' => $bankAcc,
                    'leg_role' => JournalEntrySource::ROLE_PRIMARY,
                ]
            );

            $tx->posted_journal_entry_id = $draft->id;
            $tx->save();

            AuditService::log(
                action: 'UNAPPLIED_CASH_DRAFT_CREATED',
                modelType: BusinessTransaction::class,
                modelId: $tx->id,
                notes: "Unapplied cash draft journal #{$draft->entry_number} generated (Credit Liability Account 2200).",
                userId: $maker->id,
                organizationId: $orgId
            );

            return $draft->fresh(['lines', 'sources']);
        });
    }

    /**
     * Resolves an unapplied cash transaction by a human reviewer (e.g. reclassifying after donor/purpose identified).
     *
     * @param  array{
     *     target_account_id?: int,
     *     counterparty_id?: int,
     *     transaction_type?: string,
     *     notes?: string
     * }  $resolutionData
     *
     * @throws CrossTenantViolationException
     * @throws InvalidWorkflowStateException
     */
    public function resolve(
        BusinessTransaction|int $transaction,
        array $resolutionData,
        User $resolver
    ): BusinessTransaction {
        return DB::transaction(function () use ($transaction, $resolutionData, $resolver) {
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

            // Resolver RBAC Guard
            if (! $resolver->isSuperAdmin()) {
                $hasPerm = $resolver->can('journal.review') || $resolver->can('journal.approve')
                    || (method_exists($resolver, 'hasPermissionTo') && ($resolver->hasPermissionTo('journal.review') || $resolver->hasPermissionTo('journal.approve')));
                if (! $hasPerm) {
                    throw new AuthorizationException("User #{$resolver->id} does not have required permission to resolve unapplied cash.");
                }
            }

            // Period Lock Guard
            if ($tx->is_period_locked) {
                throw new ClosedFiscalPeriodException(
                    $tx->transaction_date ? (string) $tx->transaction_date : now()->format('Y-m-d'),
                    'locked',
                    "Cannot resolve unapplied cash: transaction {$tx->transaction_number} is period locked."
                );
            }

            if ($tx->isPosted()) {
                throw new InvalidWorkflowStateException(
                    "Cannot re-resolve an already posted transaction {$tx->transaction_number}."
                );
            }

            $before = $tx->toArray();

            // Clean up old unposted draft journal if existing
            $source = JournalEntrySource::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('source_type', JournalEntrySource::TYPE_BUSINESS_TRANSACTION)
                ->where('source_id', $tx->id)
                ->first();

            if ($source) {
                $draft = JournalEntry::withoutGlobalScopes()->find($source->journal_entry_id);
                if ($draft && $draft->isDraft()) {
                    $source->delete();
                    $draft->lines()->delete();
                    $draft->delete();
                }
            }

            $tx->posted_journal_entry_id = null;

            // Apply resolution with cross-tenant and account state validation
            if (! empty($resolutionData['target_account_id'])) {
                $targetAccountId = (int) $resolutionData['target_account_id'];
                $account = Account::withoutGlobalScopes()->find($targetAccountId);
                if (! $account) {
                    throw new UnresolvedAccountMappingException("ACCOUNT:{$targetAccountId}", "Target account #{$targetAccountId} does not exist.");
                }
                if ((int) $account->organization_id !== $orgId) {
                    throw new CrossTenantViolationException(
                        "Target account #{$targetAccountId} belongs to organization {$account->organization_id}, not active tenant {$orgId}."
                    );
                }
                if (! $account->is_active) {
                    throw new InactiveAccountException($account->code, "Target account {$account->code} is inactive.");
                }
                if (! $account->is_postable) {
                    throw new NonPostableAccountException($account->code, $account->name ?? '');
                }
                $tx->target_account_id = $targetAccountId;
            }

            if (! empty($resolutionData['counterparty_id'])) {
                $cpId = (int) $resolutionData['counterparty_id'];
                $counterparty = Counterparty::withoutGlobalScopes()->find($cpId);
                if (! $counterparty) {
                    throw new InvalidArgumentException("Counterparty #{$cpId} does not exist.");
                }
                if ((int) $counterparty->organization_id !== $orgId) {
                    throw new CrossTenantViolationException(
                        "Counterparty #{$cpId} belongs to organization {$counterparty->organization_id}, not active tenant {$orgId}."
                    );
                }
                if (! $counterparty->is_active) {
                    throw new InvalidArgumentException("Counterparty #{$cpId} is inactive.");
                }
                $tx->counterparty_id = $cpId;
            }

            if (! empty($resolutionData['transaction_type'])) {
                if ($resolutionData['transaction_type'] === 'BANK_TRANSFER') {
                    throw new InvalidWorkflowStateException('Cannot reclassify unapplied cash to BANK_TRANSFER via this endpoint. Use bank transfer pairing workflow instead.');
                }
                $tx->transaction_type = $resolutionData['transaction_type'];
            }

            $notes = $resolutionData['notes'] ?? 'Resolved from Unapplied Cash Queue';
            $tx->notes = trim(($tx->notes ? $tx->notes."\n" : '')."Resolution by User #{$resolver->id}: {$notes}");
            $tx->classification_status = ClassificationStatus::REVIEWED;
            $tx->posting_error = null;
            $tx->save();

            AuditService::log(
                action: 'UNAPPLIED_CASH_RESOLVED',
                modelType: BusinessTransaction::class,
                modelId: $tx->id,
                oldValues: $before,
                newValues: $tx->fresh()->toArray(),
                notes: "Unapplied cash resolved by User #{$resolver->id}: {$notes}",
                userId: $resolver->id,
                organizationId: $orgId
            );

            return $tx->fresh();
        });
    }

    /**
     * Query for the Unapplied Cash Review Queue.
     */
    public function getUnappliedCashQueueQuery(int $organizationId): Builder
    {
        return BusinessTransaction::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('transaction_type', 'UNAPPLIED_CASH')
            ->whereNotIn('status', ['posted', 'rejected', 'reversed'])
            ->orderBy('transaction_date')
            ->orderBy('id');
    }
}
