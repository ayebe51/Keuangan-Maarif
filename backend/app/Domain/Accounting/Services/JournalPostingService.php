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
