<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Exceptions\AlreadyReversedException;
use App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domain\Accounting\Exceptions\NonPostableAccountException;
use App\Domain\Accounting\Exceptions\SegregationOfDutiesException;
use App\Domain\Accounting\Exceptions\UnbalancedJournalException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\ValueObjects\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Models\User;
use Carbon\Carbon;
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
            if (!empty($data['lines']) && is_array($data['lines'])) {
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

            // Guard 1: Status must be DRAFT
            if (!$entry->isDraft()) {
                throw new InvalidArgumentException("Only DRAFT journals can be posted. Current status: {$entry->status}.");
            }

            // Guard 2: Segregation of Duties (DEC-002: Maker != Checker, NO implicit bypass)
            if ((int) $entry->created_by === (int) $user->id) {
                throw new SegregationOfDutiesException($user->id, $entry->entry_number);
            }

            // Guard 3: Fiscal period must be OPEN (INV-004)
            $this->fiscalPeriodService->ensureDateInOpenPeriod($entry->entry_date->format('Y-m-d'), $orgId);

            // Guard 4: Must have at least 2 lines
            $lines = $entry->lines()->get();
            if ($lines->count() < 2) {
                throw new InvalidArgumentException("Journal must contain at least 2 lines to form a valid double-entry.");
            }

            // Guard 5: Leaf postable & Cross-tenant dimension checks & Balancing calculation
            $totalDebit = Money::zero();
            $totalCredit = Money::zero();

            foreach ($lines as $line) {
                // Ensure account is leaf postable and belongs to tenant
                $account = $this->accountService->ensurePostable($line->account_id, $orgId);

                // Ensure related foreign key dimensions belong to tenant
                $this->validateDimensionTenancy($line, $orgId);

                $debit = Money::of($line->debit);
                $credit = Money::of($line->credit);

                // Enforce DB invariant in app layer: exactly one side > 0
                if (($debit->isPositive() && $credit->isPositive()) || ($debit->isZero() && $credit->isZero())) {
                    throw new InvalidArgumentException("Line {$line->line_number} must have exactly one positive side (debit or credit).");
                }

                $totalDebit = $totalDebit->add($debit);
                $totalCredit = $totalCredit->add($credit);
            }

            // Guard 6: Total Debit must exactly equal Total Credit (INV-002)
            if (!$totalDebit->equals($totalCredit)) {
                throw new UnbalancedJournalException($totalDebit->getAmount(), $totalCredit->getAmount());
            }

            if ($totalDebit->isZero()) {
                throw new UnbalancedJournalException('0.00', '0.00', 'Total journal balance cannot be zero.');
            }

            // Commit posting state
            $before = $entry->toArray();

            $entry->status = JournalEntry::STATUS_POSTED;
            $entry->posted_at = now();
            $entry->posted_by = $user->id;
            $entry->save();

            AuditService::log(
                action: 'JOURNAL_POSTED',
                modelType: JournalEntry::class,
                modelId: $entry->id,
                oldValues: $before,
                newValues: $entry->fresh()->toArray(),
                userId: $user->id,
                organizationId: $orgId
            );

            return $entry->fresh(['lines']);
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
            if (!$originalEntry->isPosted()) {
                throw new InvalidArgumentException("Only POSTED journals can be reversed. Current status: {$originalEntry->status}.");
            }

            // Guard 3: Segregation of Duties on reversal (Original maker cannot reverse without check)
            if ((int) $originalEntry->created_by === (int) $user->id && !$user->isSuperAdmin()) {
                throw new SegregationOfDutiesException($user->id, $originalEntry->entry_number, "Maker cannot reverse their own posted journal.");
            }

            // Guard 4: Target reversal date must be in OPEN fiscal period (DEC-003 & GAP-004)
            if ($reversalDate) {
                $effectiveDate = $reversalDate;
                $fiscalPeriod = $this->fiscalPeriodService->ensureDateInOpenPeriod($effectiveDate, $orgId);
            } else {
                // Try now() or current open period
                $today = now()->format('Y-m-d');
                $openPeriod = FiscalPeriod::withoutGlobalScopes()
                    ->where('organization_id', $orgId)
                    ->open()
                    ->where('start_date', '<=', $today)
                    ->where('end_date', '>=', $today)
                    ->first();

                if ($openPeriod) {
                    $effectiveDate = $today;
                    $fiscalPeriod = $openPeriod;
                } else {
                    $currentPeriod = FiscalPeriod::withoutGlobalScopes()
                        ->where('organization_id', $orgId)
                        ->open()
                        ->where('is_current', true)
                        ->first();

                    if (!$currentPeriod) {
                        throw new ClosedFiscalPeriodException($today, 'closed', "No open fiscal period available for reversal.");
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
                'reference' => 'REV:' . $originalEntry->entry_number,
                'description' => 'Reversal of ' . $originalEntry->entry_number . ($reason ? ' - ' . $reason : ''),
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
                    'description' => 'Reversal line of ' . $origLine->line_number,
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
            if (!$bank || (int)$bank->organization_id !== $organizationId) {
                throw new CrossTenantViolationException("Bank Account {$line->bank_account_id} belongs to a different organization.");
            }
        }

        if ($line->fund_id) {
            $fund = DB::table('funds')->where('id', $line->fund_id)->first();
            if (!$fund || (int)$fund->organization_id !== $organizationId) {
                throw new CrossTenantViolationException("Fund {$line->fund_id} belongs to a different organization.");
            }
        }

        if ($line->counterparty_id) {
            $cp = DB::table('counterparties')->where('id', $line->counterparty_id)->first();
            if (!$cp || (int)$cp->organization_id !== $organizationId) {
                throw new CrossTenantViolationException("Counterparty {$line->counterparty_id} belongs to a different organization.");
            }
        }
    }
}
