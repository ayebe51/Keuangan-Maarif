<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DTOs\AutomationPostingAuthorization;
use App\Domain\Accounting\Exceptions\AlreadyReversedException;
use App\Domain\Accounting\Exceptions\AutomatedPostingAlreadyExecutedException;
use App\Domain\Accounting\Exceptions\AutoPostAmountExceededException;
use App\Domain\Accounting\Exceptions\AutoPostDisabledException;
use App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domain\Accounting\Exceptions\InvalidAutomatedPostingSourceException;
use App\Domain\Accounting\Exceptions\InvalidAutomationAuthorizationException;
use App\Domain\Accounting\Exceptions\JournalNotDraftException;
use App\Domain\Accounting\Exceptions\RuleNotApprovedException;
use App\Domain\Accounting\Exceptions\RuleRevokedException;
use App\Domain\Accounting\Exceptions\RuleSnapshotMismatchException;
use App\Domain\Accounting\Exceptions\SegregationOfDutiesException;
use App\Domain\Accounting\Exceptions\TransactionMakerCannotApproveRuleException;
use App\Domain\Accounting\Exceptions\UnauthorizedRuleApproverException;
use App\Domain\Accounting\Exceptions\UnbalancedJournalException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\ValueObjects\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class JournalPostingService
{
    public function __construct(
        protected JournalNumberAllocator $allocator,
        protected FiscalPeriodService $fiscalPeriodService,
        protected AccountService $accountService
    ) {}

    public function createDraft(array $data, User $user): JournalEntry
    {
        return DB::transaction(function () use ($data, $user) {
            $orgId = (int) $data['organization_id'];
            $entryDate = $data['entry_date'] ?? now()->format('Y-m-d');
            $year = (int) Carbon::parse($entryDate)->format('Y');

            // 1. Resolve / Ensure Fiscal Period
            $fiscalPeriod = $this->fiscalPeriodService->ensureDateInOpenPeriod($entryDate, $orgId);

            // 2. Allocate safe atomic entry_number
            $entryNumber = $this->allocator->allocate($orgId, $year);

            // 3. Create JournalEntry Draft
            $entry = JournalEntry::create([
                'organization_id' => $orgId,
                'fiscal_period_id' => $fiscalPeriod->id,
                'entry_number' => $entryNumber,
                'entry_date' => $entryDate,
                'entry_type' => $data['entry_type'] ?? JournalEntry::TYPE_PAYMENT,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? 'Draft journal entry',
                'status' => JournalEntry::STATUS_DRAFT,
                'created_by' => $user->id,
            ]);

            // 4. Create lines
            if (! empty($data['lines']) && is_array($data['lines'])) {
                $lineNumber = 1;
                foreach ($data['lines'] as $lineData) {
                    $this->validateAndCreateLine($entry, $lineData, $lineNumber++);
                }
            }

            // 5. Audit log
            AuditService::log(
                action: 'JOURNAL_DRAFT_CREATED',
                modelType: JournalEntry::class,
                modelId: $entry->id,
                newValues: $entry->toArray(),
                userId: $user->id,
                organizationId: $orgId
            );

            return $entry->fresh(['lines']);
        });
    }

    public function post(JournalEntry $entry, User $user): JournalEntry
    {
        return DB::transaction(function () use ($entry, $user) {
            $orgId = (int) $entry->organization_id;

            // Lock journal entry row to prevent concurrent posting race conditions
            $lockedEntry = JournalEntry::withoutGlobalScopes()
                ->where('id', $entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Guard 1: Status must be DRAFT
            if (! $lockedEntry->isDraft()) {
                throw new InvalidArgumentException("Only DRAFT journals can be posted. Current status: {$lockedEntry->status}.");
            }

            // Guard 2: Segregation of Duties (DEC-002: Maker != Checker, NO implicit bypass)
            if ((int) $lockedEntry->created_by === (int) $user->id) {
                throw new SegregationOfDutiesException($user->id, $lockedEntry->entry_number);
            }

            // Guard 3: Fiscal period must be OPEN (INV-004) with row-level lock against TOCTOU
            $this->fiscalPeriodService->ensureDateInOpenPeriod(
                $lockedEntry->entry_date->format('Y-m-d'),
                $orgId,
                lockForUpdate: true
            );

            // Guard 4-6: Shared Double-Entry Invariants
            $this->validateDoubleEntryInvariants($lockedEntry, $orgId);

            // Commit posting state
            $before = $lockedEntry->toArray();

            $lockedEntry->status = JournalEntry::STATUS_POSTED;
            $lockedEntry->posted_at = now();
            $lockedEntry->posted_by = $user->id;
            $lockedEntry->posted_by_rule_id = null;
            $lockedEntry->save();

            AuditService::log(
                action: 'JOURNAL_POSTED',
                modelType: JournalEntry::class,
                modelId: $lockedEntry->id,
                oldValues: $before,
                newValues: $lockedEntry->fresh()->toArray(),
                userId: $user->id,
                organizationId: $orgId
            );

            return $lockedEntry->fresh(['lines']);
        });
    }

    /**
     * Formal system-automated journal posting path.
     * Enforces server-authoritative rule governance, zero identity spoofing,
     * Segregation of Duties, and single accounting core integration.
     */
    public function postSystemAutomated(
        JournalEntry $entry,
        AutomationPostingAuthorization $auth
    ): JournalEntry {
        return DB::transaction(function () use ($entry, $auth) {
            $orgId = (int) $entry->organization_id;

            // 1. Cross-Tenant Guard on Authorization DTO
            if ((int) $auth->getOrganizationId() !== $orgId) {
                throw new CrossTenantViolationException("Authorization organization mismatch: expected {$orgId}, got {$auth->getOrganizationId()}.");
            }

            // 2. Lock JournalEntry (Order 1 in deadlock prevention hierarchy)
            $lockedEntry = JournalEntry::where('id', $entry->id)
                ->where('organization_id', $orgId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedEntry->isDraft()) {
                if ($lockedEntry->isPosted()) {
                    throw new AutomatedPostingAlreadyExecutedException($lockedEntry->entry_number, "Journal {$lockedEntry->entry_number} has already been posted to the ledger.");
                }
                throw new JournalNotDraftException($lockedEntry->entry_number, $lockedEntry->status);
            }

            // 3. Lock BusinessTransaction (Order 2 in hierarchy)
            $businessTransaction = BusinessTransaction::withoutGlobalScopes()
                ->where('id', $auth->getBusinessTransactionId())
                ->lockForUpdate()
                ->first();

            if (! $businessTransaction) {
                throw new InvalidAutomatedPostingSourceException("BusinessTransaction #{$auth->getBusinessTransactionId()} not found.");
            }

            if ((int) $businessTransaction->organization_id !== $orgId) {
                throw new CrossTenantViolationException("BusinessTransaction #{$businessTransaction->id} belongs to a different organization.");
            }

            // 4. Verify Canonical Source Provenance (journal_entry_sources or journal_entries.business_transaction_id)
            $isLinkedViaSource = DB::table('journal_entry_sources')
                ->where('organization_id', $orgId)
                ->where('journal_entry_id', $lockedEntry->id)
                ->where('source_type', 'business_transaction')
                ->where('source_id', $businessTransaction->id)
                ->exists();

            $isLinkedDirectly = (int) $lockedEntry->business_transaction_id === (int) $businessTransaction->id;

            if (! $isLinkedViaSource && ! $isLinkedDirectly) {
                throw new InvalidAutomatedPostingSourceException("Journal draft {$lockedEntry->id} is not linked to BusinessTransaction {$businessTransaction->id}.");
            }

            // 5. Already posted check on BusinessTransaction
            if ($businessTransaction->status === 'posted' || $businessTransaction->posted_journal_entry_id !== null) {
                throw new AutomatedPostingAlreadyExecutedException(
                    $businessTransaction->transaction_number,
                    "BusinessTransaction {$businessTransaction->transaction_number} has already been posted to the ledger (Journal ID: {$businessTransaction->posted_journal_entry_id})."
                );
            }

            // 6. Domain Category & Subledger Restrictions
            if ($businessTransaction->transaction_type === 'RECEIVABLE_PAYMENT') {
                throw new InvalidAutomationAuthorizationException(
                    'Automated posting for RECEIVABLE_PAYMENT is strictly blocked pending Phase 8 subledger implementation. Must be routed to Human Approval Queue.'
                );
            }

            if ($businessTransaction->transaction_type === 'UNAPPLIED_CASH') {
                throw new InvalidAutomationAuthorizationException(
                    'Automated posting for UNAPPLIED_CASH is prohibited. Must be routed to Human Review.'
                );
            }

            if ($businessTransaction->transaction_type === 'BANK_TRANSFER') {
                if ($businessTransaction->transfer_pairing_status === 'PAIRING_AMBIGUOUS') {
                    throw new InvalidAutomationAuthorizationException(
                        'Automated posting blocked for bank transfer with PAIRING_AMBIGUOUS status. Routed to Transfer Review Queue.'
                    );
                }
                if ($businessTransaction->transfer_pairing_status !== 'PAIRED') {
                    throw new InvalidAutomationAuthorizationException(
                        "Automated posting blocked for bank transfer: leg pairing status is '{$businessTransaction->transfer_pairing_status}' (must be 'PAIRED')."
                    );
                }
            }

            // 7. Lock ClassificationRule (Order 3 in hierarchy)
            $rule = ClassificationRule::withoutGlobalScopes()
                ->where('id', $auth->getClassificationRuleId())
                ->lockForUpdate()
                ->first();

            if (! $rule) {
                throw new InvalidAutomationAuthorizationException("Classification rule #{$auth->getClassificationRuleId()} not found.");
            }

            if ((int) $rule->organization_id !== $orgId) {
                throw new CrossTenantViolationException("ClassificationRule #{$rule->id} belongs to a different organization.");
            }

            // 8. Server-Authoritative Rule Governance Validation
            if (! $rule->is_active) {
                throw new RuleRevokedException($rule->id, "Classification rule #{$rule->id} is inactive / revoked.");
            }

            if (! $rule->approved_by || ! $rule->approved_at) {
                throw new RuleNotApprovedException($rule->id, "Classification rule #{$rule->id} has not been approved.");
            }

            $approver = User::withoutGlobalScopes()->find($rule->approved_by);
            if (! $approver || ! $approver->is_active) {
                throw new UnauthorizedRuleApproverException(
                    (int) $rule->approved_by,
                    "Rule Approver #{$rule->approved_by} is inactive or does not exist."
                );
            }

            if (! $approver->hasPermissionTo('journal.approve', 'sanctum') && ! $approver->hasPermissionTo('journal.approve')) {
                throw new UnauthorizedRuleApproverException(
                    (int) $approver->id,
                    "Rule Approver #{$approver->id} does not hold the required 'journal.approve' permission."
                );
            }

            if (! $rule->auto_post) {
                throw new AutoPostDisabledException($rule->id, "Classification rule #{$rule->id} has auto_post set to false.");
            }

            $maxAutoPostAmount = Money::of($rule->max_auto_post_amount ?? '0.00');
            if (! $maxAutoPostAmount->isPositive()) {
                throw new AutoPostDisabledException(
                    $rule->id,
                    "Classification rule #{$rule->id} has max_auto_post_amount of 0.00 (auto-post disabled by default until explicitly configured & approved)."
                );
            }

            $txAmount = Money::of($businessTransaction->amount);
            if (! $txAmount->isPositive()) {
                throw new InvalidAutomationAuthorizationException('Transaction amount must be strictly positive.');
            }

            if ($txAmount->isGreaterThan($maxAutoPostAmount)) {
                throw new AutoPostAmountExceededException(
                    $txAmount->getAmount(),
                    $maxAutoPostAmount->getAmount(),
                    $rule->id
                );
            }

            // Effective Date Range
            $txDate = $businessTransaction->transaction_date instanceof Carbon
                ? $businessTransaction->transaction_date->format('Y-m-d')
                : (string) $businessTransaction->transaction_date;

            if ($rule->effective_from) {
                $effFrom = $rule->effective_from instanceof Carbon
                    ? $rule->effective_from->format('Y-m-d')
                    : (string) $rule->effective_from;
                if ($txDate < $effFrom) {
                    throw new InvalidAutomationAuthorizationException("Transaction date '{$txDate}' is before rule effective_from '{$effFrom}'.");
                }
            }

            if ($rule->effective_to) {
                $effTo = $rule->effective_to instanceof Carbon
                    ? $rule->effective_to->format('Y-m-d')
                    : (string) $rule->effective_to;
                if ($txDate > $effTo) {
                    throw new InvalidAutomationAuthorizationException("Transaction date '{$txDate}' is after rule effective_to '{$effTo}'.");
                }
            }

            // Rule Version Check
            if ((int) $rule->version !== (int) $auth->getExpectedRuleVersion()) {
                throw new RuleSnapshotMismatchException(
                    "Rule version mismatch for Rule #{$rule->id}: expected {$auth->getExpectedRuleVersion()}, database has {$rule->version}."
                );
            }

            // 9. Segregation of Duties (Maker-Checker)
            // 9A: Rule Creator != Rule Approver
            if ($rule->created_by !== null && (int) $rule->created_by === (int) $rule->approved_by) {
                throw new SegregationOfDutiesException(
                    (int) $rule->approved_by,
                    $lockedEntry->entry_number,
                    "Segregation of Duties Violation: Rule Creator #{$rule->created_by} cannot approve their own rule. Self-approved rules are strictly prohibited."
                );
            }

            // 9B: Transaction Maker != Rule Approver
            $makerId = $lockedEntry->created_by;
            if (! $makerId && $businessTransaction->bank_transaction_id) {
                $bankTx = DB::table('bank_transactions')->where('id', $businessTransaction->bank_transaction_id)->first();
                if ($bankTx && $bankTx->bank_import_id) {
                    $import = DB::table('bank_imports')->where('id', $bankTx->bank_import_id)->first();
                    $makerId = $import?->imported_by;
                }
            }

            if (! $makerId) {
                throw new TransactionMakerCannotApproveRuleException(
                    0,
                    $lockedEntry->entry_number,
                    'Transaction maker cannot be verified from persistent provenance. Auto-post rejected.'
                );
            }

            if ((int) $makerId === (int) $rule->approved_by) {
                throw new TransactionMakerCannotApproveRuleException(
                    (int) $makerId,
                    $lockedEntry->entry_number,
                    "Segregation of Duties Violation: Transaction Maker (User #{$makerId}) cannot be the Rule Approver (User #{$rule->approved_by}) for automated posting."
                );
            }

            // 10. Rule Snapshot Integrity Verification
            if ($businessTransaction->rule_snapshot) {
                $snapshot = is_array($businessTransaction->rule_snapshot)
                    ? $businessTransaction->rule_snapshot
                    : json_decode($businessTransaction->rule_snapshot, true);

                if (isset($snapshot['rule_id']) && (int) $snapshot['rule_id'] !== (int) $rule->id) {
                    throw new RuleSnapshotMismatchException("Rule ID in snapshot ({$snapshot['rule_id']}) does not match current rule #{$rule->id}.");
                }
                if (isset($snapshot['version']) && (int) $snapshot['version'] !== (int) $rule->version) {
                    throw new RuleSnapshotMismatchException("Rule version in snapshot ({$snapshot['version']}) does not match current rule version {$rule->version}.");
                }
            }

            if ($auth->getExpectedRuleSnapshotHash() !== null) {
                $currentSnapshot = $businessTransaction->rule_snapshot ?? $rule->toSnapshot();
                $actualHash = AutomationPostingAuthorization::computeSnapshotHash($currentSnapshot);
                if ($actualHash !== $auth->getExpectedRuleSnapshotHash()) {
                    throw new RuleSnapshotMismatchException("Rule snapshot hash mismatch: expected {$auth->getExpectedRuleSnapshotHash()}, actual {$actualHash}.");
                }
            }

            // 11. Accounting Core Double-Entry & Fiscal Period Verification (Shared Core Routine) with row-level lock against TOCTOU
            $entryDateStr = $lockedEntry->entry_date instanceof Carbon
                ? $lockedEntry->entry_date->format('Y-m-d')
                : (string) $lockedEntry->entry_date;

            $this->fiscalPeriodService->ensureDateInOpenPeriod($entryDateStr, $orgId, lockForUpdate: true);
            $this->validateDoubleEntryInvariants($lockedEntry, $orgId);

            // 12. Commit Posting State
            $beforeEntry = $lockedEntry->toArray();

            $lockedEntry->status = JournalEntry::STATUS_POSTED;
            $lockedEntry->posted_at = now();
            $lockedEntry->posted_by = null; // ZERO IDENTITY SPOOFING: Must remain NULL for system daemon
            $lockedEntry->posted_by_rule_id = $rule->id;
            $lockedEntry->save();

            // 13. Update BusinessTransaction Linkage & Posting State
            $businessTransaction->status = 'posted';
            $businessTransaction->posted_journal_entry_id = $lockedEntry->id;
            $businessTransaction->posted_at = $lockedEntry->posted_at;
            $businessTransaction->posted_by = null;
            $businessTransaction->save();

            // Synchronize paired transfer twin if present
            if ($businessTransaction->isTransfer() && $businessTransaction->paired_business_transaction_id) {
                $twin = BusinessTransaction::withoutGlobalScopes()->find($businessTransaction->paired_business_transaction_id);
                if ($twin && ! $twin->isPosted()) {
                    $twin->status = 'posted';
                    $twin->posted_journal_entry_id = $lockedEntry->id;
                    $twin->posted_at = $lockedEntry->posted_at;
                    $twin->posted_by = null;
                    $twin->save();
                }
            }

            // 14. Record Audit Event: JOURNAL_POSTED_AUTOMATED (SYSTEM_DAEMON)
            AuditService::logSystem(
                action: 'JOURNAL_POSTED_AUTOMATED',
                modelType: JournalEntry::class,
                modelId: $lockedEntry->id,
                oldValues: $beforeEntry,
                newValues: array_merge($lockedEntry->fresh()->toArray(), [
                    'executor' => 'SYSTEM_DAEMON',
                    'posted_by_rule_id' => $rule->id,
                    'rule_version' => $rule->version,
                    'rule_approver_id' => $rule->approved_by,
                    'rule_creator_id' => $rule->created_by,
                    'transaction_maker_id' => (int) $makerId,
                    'business_transaction_id' => $businessTransaction->id,
                    'amount' => $businessTransaction->amount,
                    'currency' => 'IDR',
                    'source_provenance' => 'journal_entry_sources',
                    'authorization_decision' => 'APPROVED_AUTOMATED',
                ]),
                notes: "System automated journal posting authorized by Rule #{$rule->id} (v{$rule->version})",
                organizationId: $orgId
            );

            return $lockedEntry->fresh(['lines']);
        });
    }

    public function reverse(JournalEntry $originalEntry, User $user, ?string $reversalDate = null, string $reason = ''): JournalEntry
    {
        return DB::transaction(function () use ($originalEntry, $user, $reversalDate, $reason) {
            $orgId = (int) $originalEntry->organization_id;

            // Guard 1: Must not already be reversed (DEC-003 & AT-012)
            if ($originalEntry->isReversed() || $originalEntry->reversed_entry_id !== null) {
                throw new AlreadyReversedException($originalEntry->entry_number);
            }

            // Guard 2: Status must be POSTED
            if (! $originalEntry->isPosted()) {
                throw new InvalidArgumentException("Only POSTED journals can be reversed. Current status: {$originalEntry->status}.");
            }

            // Guard 3: Segregation of Duties on reversal (Original maker cannot reverse without check)
            if ((int) $originalEntry->created_by === (int) $user->id && ! $user->isSuperAdmin()) {
                throw new SegregationOfDutiesException($user->id, $originalEntry->entry_number, 'Maker cannot reverse their own posted journal.');
            }

            // Guard 4: Target reversal date must be in OPEN fiscal period (DEC-003 & GAP-004) with row-level lock against TOCTOU
            if ($reversalDate) {
                $effectiveDate = $reversalDate;
                $fiscalPeriod = $this->fiscalPeriodService->ensureDateInOpenPeriod($effectiveDate, $orgId, lockForUpdate: true);
            } else {
                // Try now() or current open period
                $today = now()->format('Y-m-d');
                $openPeriod = FiscalPeriod::withoutGlobalScopes()
                    ->where('organization_id', $orgId)
                    ->open()
                    ->where('start_date', '<=', $today)
                    ->where('end_date', '>=', $today)
                    ->lockForUpdate()
                    ->first();

                if ($openPeriod) {
                    $effectiveDate = $today;
                    $fiscalPeriod = $openPeriod;
                } else {
                    $currentPeriod = FiscalPeriod::withoutGlobalScopes()
                        ->where('organization_id', $orgId)
                        ->open()
                        ->where('is_current', true)
                        ->lockForUpdate()
                        ->first();

                    if (! $currentPeriod) {
                        throw new ClosedFiscalPeriodException($today, 'closed', 'No open fiscal period available for reversal.');
                    }
                    $effectiveDate = $currentPeriod->end_date->format('Y-m-d');
                    $fiscalPeriod = $currentPeriod;
                }
            }

            $year = (int) Carbon::parse($effectiveDate)->format('Y');
            $reversalNumber = $this->allocator->allocate($orgId, $year);

            // 1. Create Reversal Journal Entry as DRAFT first so lines can be added
            $reversalEntry = JournalEntry::create([
                'organization_id' => $orgId,
                'fiscal_period_id' => $fiscalPeriod->id,
                'entry_number' => $reversalNumber,
                'entry_date' => $effectiveDate,
                'entry_type' => JournalEntry::TYPE_REVERSAL,
                'reference' => 'REV:'.$originalEntry->entry_number,
                'description' => 'Reversal of '.$originalEntry->entry_number.($reason ? ' - '.$reason : ''),
                'status' => JournalEntry::STATUS_DRAFT,
                'reversed_entry_id' => $originalEntry->id,
                'created_by' => $user->id,
            ]);

            // 2. Mirror lines (Debit <-> Credit)
            $originalLines = $originalEntry->lines()->orderBy('line_number')->get();
            foreach ($originalLines as $origLine) {
                JournalLine::create([
                    'organization_id' => $orgId,
                    'journal_entry_id' => $reversalEntry->id,
                    'line_number' => $origLine->line_number,
                    'account_id' => $origLine->account_id,
                    'debit' => $origLine->credit, // Mirrored
                    'credit' => $origLine->debit, // Mirrored
                    'description' => 'Reversal line of '.$origLine->line_number,
                    'bank_account_id' => $origLine->bank_account_id,
                    'counterparty_id' => $origLine->counterparty_id,
                    'fund_id' => $origLine->fund_id,
                    'receivable_id' => $origLine->receivable_id,
                ]);
            }

            // 3. Post reversal entry
            $reversalEntry->status = JournalEntry::STATUS_POSTED;
            $reversalEntry->posted_at = now();
            $reversalEntry->posted_by = $user->id;
            $reversalEntry->save();

            // 4. Mark original as reversed via dedicated domain method
            $originalEntry->markAsReversed($reversalEntry->id);

            // 4. Audit Log
            AuditService::log(
                action: 'JOURNAL_REVERSED',
                modelType: JournalEntry::class,
                modelId: $originalEntry->id,
                oldValues: ['status' => JournalEntry::STATUS_POSTED],
                newValues: ['status' => JournalEntry::STATUS_REVERSED, 'reversed_entry_id' => $reversalEntry->id],
                notes: $reason,
                userId: $user->id,
                organizationId: $orgId
            );

            return $reversalEntry->fresh(['lines']);
        });
    }

    /**
     * Shared double-entry accounting invariants verification routine.
     * Enforces:
     * - Minimum 2 lines
     * - Leaf postable account check (and tenancy check)
     * - Line dimension tenancy checks
     * - Exactly one positive side (debit or credit) per line
     * - Sum(debit) == Sum(credit) > 0
     *
     * @return array{totalDebit: Money, totalCredit: Money, lines: Collection}
     */
    protected function validateDoubleEntryInvariants(JournalEntry $entry, int $orgId): array
    {
        $lines = $entry->lines()->get();
        if ($lines->count() < 2) {
            throw new InvalidArgumentException('Journal must contain at least 2 lines to form a valid double-entry.');
        }

        $totalDebit = Money::zero();
        $totalCredit = Money::zero();

        foreach ($lines as $line) {
            $this->accountService->ensurePostable($line->account_id, $orgId);
            $this->validateDimensionTenancy($line, $orgId);

            $debit = Money::of($line->debit);
            $credit = Money::of($line->credit);

            if (($debit->isPositive() && $credit->isPositive()) || ($debit->isZero() && $credit->isZero())) {
                throw new InvalidArgumentException("Line {$line->line_number} must have exactly one positive side (debit or credit).");
            }

            $totalDebit = $totalDebit->add($debit);
            $totalCredit = $totalCredit->add($credit);
        }

        if (! $totalDebit->equals($totalCredit)) {
            throw new UnbalancedJournalException($totalDebit->getAmount(), $totalCredit->getAmount());
        }

        if ($totalDebit->isZero()) {
            throw new UnbalancedJournalException('0.00', '0.00', 'Total journal balance cannot be zero.');
        }

        return [
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'lines' => $lines,
        ];
    }

    private function validateAndCreateLine(JournalEntry $entry, array $lineData, int $lineNumber): JournalLine
    {
        $orgId = (int) $entry->organization_id;
        $accountId = (int) $lineData['account_id'];

        // Postability & Tenancy check on Account
        $this->accountService->ensurePostable($accountId, $orgId);

        $debit = Money::of($lineData['debit'] ?? '0.00');
        $credit = Money::of($lineData['credit'] ?? '0.00');

        if (($debit->isPositive() && $credit->isPositive()) || ($debit->isZero() && $credit->isZero())) {
            throw new InvalidArgumentException("Line {$lineNumber} must have exactly one positive side (debit or credit).");
        }

        $line = new JournalLine([
            'organization_id' => $orgId,
            'journal_entry_id' => $entry->id,
            'line_number' => $lineNumber,
            'account_id' => $accountId,
            'debit' => $debit->getAmount(),
            'credit' => $credit->getAmount(),
            'description' => $lineData['description'] ?? null,
            'bank_account_id' => $lineData['bank_account_id'] ?? null,
            'counterparty_id' => $lineData['counterparty_id'] ?? null,
            'fund_id' => $lineData['fund_id'] ?? null,
            'receivable_id' => $lineData['receivable_id'] ?? null,
        ]);

        $this->validateDimensionTenancy($line, $orgId);
        $line->save();

        return $line;
    }

    private function validateDimensionTenancy(JournalLine $line, int $organizationId): void
    {
        if ($line->bank_account_id) {
            $bank = DB::table('bank_accounts')->where('id', $line->bank_account_id)->first();
            if (! $bank || (int) $bank->organization_id !== $organizationId) {
                throw new CrossTenantViolationException("Bank Account {$line->bank_account_id} belongs to a different organization.");
            }
        }

        if ($line->fund_id) {
            $fund = DB::table('funds')->where('id', $line->fund_id)->first();
            if (! $fund || (int) $fund->organization_id !== $organizationId) {
                throw new CrossTenantViolationException("Fund {$line->fund_id} belongs to a different organization.");
            }
        }

        if ($line->counterparty_id) {
            $cp = DB::table('counterparties')->where('id', $line->counterparty_id)->first();
            if (! $cp || (int) $cp->organization_id !== $organizationId) {
                throw new CrossTenantViolationException("Counterparty {$line->counterparty_id} belongs to a different organization.");
            }
        }
    }
}
