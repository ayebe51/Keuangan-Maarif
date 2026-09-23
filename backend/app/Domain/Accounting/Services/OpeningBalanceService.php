<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Exceptions\AlreadyPostedException;
use App\Domain\Accounting\Exceptions\OpeningBalanceConfigurationException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\OpeningBalanceSource;
use App\Domain\Accounting\ValueObjects\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OpeningBalanceService
{
    public function __construct(
        protected JournalPostingService $postingService,
        protected FiscalPeriodService $fiscalPeriodService,
        protected AccountService $accountService
    ) {}

    public function recordSource(array $data, User $user): OpeningBalanceSource
    {
        return DB::transaction(function () use ($data, $user) {
            $orgId = (int) $data['organization_id'];

            if (!empty($data['bank_account_id'])) {
                $bank = DB::table('bank_accounts')->where('id', $data['bank_account_id'])->first();
                if (!$bank || (int)$bank->organization_id !== $orgId) {
                    throw new CrossTenantViolationException("Bank Account belongs to a different organization.");
                }
            }

            $effectiveDate = $data['effective_date'] ?? now()->format('Y-m-d');
            $period = $this->fiscalPeriodService->ensureDateInOpenPeriod($effectiveDate, $orgId);

            $amount = Money::of($data['amount']);
            if (!$amount->isPositive()) {
                throw new InvalidArgumentException("Opening balance amount must be strictly greater than zero.");
            }

            $direction = strtolower($data['direction'] ?? OpeningBalanceSource::DIRECTION_DEBIT);
            if (!in_array($direction, [OpeningBalanceSource::DIRECTION_DEBIT, OpeningBalanceSource::DIRECTION_CREDIT], true)) {
                throw new InvalidArgumentException("Invalid direction '{$direction}'. Must be 'debit' or 'credit'.");
            }

            $source = OpeningBalanceSource::create([
                'organization_id' => $orgId,
                'fiscal_period_id' => $period->id,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'effective_date' => $effectiveDate,
                'amount' => $amount->getAmount(),
                'direction' => $direction,
                'description' => $data['description'] ?? 'Opening balance declaration',
                'status' => OpeningBalanceSource::STATUS_PENDING,
                'is_fixture' => $data['is_fixture'] ?? false,
                'fixture_note' => $data['fixture_note'] ?? null,
                'created_by' => $user->id,
            ]);

            AuditService::log(
                action: 'OPENING_BALANCE_SOURCE_CREATED',
                modelType: OpeningBalanceSource::class,
                modelId: $source->id,
                newValues: $source->toArray(),
                userId: $user->id,
                organizationId: $orgId
            );

            return $source;
        });
    }

    public function processToJournal(OpeningBalanceSource $source, User $maker, User $checker, ?int $explicitAccountId = null): JournalEntry
    {
        return DB::transaction(function () use ($source, $maker, $checker, $explicitAccountId) {
            $orgId = (int) $source->organization_id;

            // Idempotency Guard (DEC-005 & AT-018)
            if ($source->isPosted()) {
                throw new AlreadyPostedException("OpeningBalanceSource #{$source->id}");
            }

            // 1. Resolve Target COA Account
            $targetAccount = null;
            if ($source->bank_account_id) {
                $targetAccount = $this->accountService->resolveAccount(
                    $orgId,
                    AccountMapping::TYPE_BANK_ACCOUNT,
                    (string) $source->bank_account_id
                );

                if (!$targetAccount) {
                    throw new OpeningBalanceConfigurationException("No COA mapping found for bank account ID {$source->bank_account_id}.");
                }
            } elseif ($explicitAccountId) {
                $targetAccount = $this->accountService->ensurePostable($explicitAccountId, $orgId);
            }

            if (!$targetAccount) {
                throw new OpeningBalanceConfigurationException("No target COA account resolved for opening balance source #{$source->id}.");
            }

            // 2. Resolve Opening Balance Equity Counterpart (Account 3100)
            $equityCounterpart = $this->accountService->resolveAccount($orgId, 'opening_balance', 'equity_counterpart');
            if (!$equityCounterpart) {
                $equityCounterpart = Account::withoutGlobalScopes()
                    ->where('organization_id', $orgId)
                    ->where('code', '3100')
                    ->where('is_postable', true)
                    ->first();
            }

            if (!$equityCounterpart) {
                throw new OpeningBalanceConfigurationException("Opening Balance Equity counterpart account (Account 3100) is not configured.");
            }

            // 3. Build Mirrored Lines according to direction (AT-016 & AT-017)
            $amount = Money::of($source->amount);
            $lines = [];

            if ($source->direction === OpeningBalanceSource::DIRECTION_DEBIT) {
                // AT-016: Debit target account, Credit Equity
                $lines[] = [
                    'account_id' => $targetAccount->id,
                    'debit' => $amount->getAmount(),
                    'credit' => '0.00',
                    'bank_account_id' => $source->bank_account_id,
                    'description' => "Saldo Awal {$targetAccount->name}",
                ];
                $lines[] = [
                    'account_id' => $equityCounterpart->id,
                    'debit' => '0.00',
                    'credit' => $amount->getAmount(),
                    'description' => 'Saldo Awal Pembukuan (Ekuitas Awal)',
                ];
            } else {
                // AT-017: Debit Equity, Credit target account (liability/fund)
                $lines[] = [
                    'account_id' => $equityCounterpart->id,
                    'debit' => $amount->getAmount(),
                    'credit' => '0.00',
                    'description' => 'Penyeimbang Saldo Awal (Ekuitas Awal)',
                ];
                $lines[] = [
                    'account_id' => $targetAccount->id,
                    'debit' => '0.00',
                    'credit' => $amount->getAmount(),
                    'bank_account_id' => $source->bank_account_id,
                    'description' => "Saldo Awal {$targetAccount->name}",
                ];
            }

            // 4. Create Draft Journal Entry
            $draft = $this->postingService->createDraft([
                'organization_id' => $orgId,
                'entry_date' => $source->effective_date->format('Y-m-d'),
                'entry_type' => JournalEntry::TYPE_OPENING_BALANCE,
                'reference' => "OBS-{$source->id}",
                'description' => $source->description ?: "Opening Balance Journal for {$targetAccount->name}",
                'lines' => $lines,
            ], $maker);

            // 5. Post Journal via JournalPostingService (Enforcing SoD: Maker != Checker)
            $posted = $this->postingService->post($draft, $checker);

            // 6. Update and Link Source
            $before = $source->toArray();
            $source->status = OpeningBalanceSource::STATUS_POSTED;
            $source->journal_entry_id = $posted->id;
            $source->save();

            AuditService::log(
                action: 'OPENING_BALANCE_POSTED',
                modelType: OpeningBalanceSource::class,
                modelId: $source->id,
                oldValues: $before,
                newValues: $source->fresh()->toArray(),
                userId: $checker->id,
                organizationId: $orgId
            );

            return $posted;
        });
    }
}
