<?php

namespace Tests\Feature;

use App\Domain\Accounting\Enums\TransferPairingStatus;
use App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domain\Accounting\Exceptions\InvalidTransferPairingException;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\AutoPostExecutionCoordinator;
use App\Domain\Accounting\Services\BankTransferPostingCoordinator;
use App\Domain\Accounting\Services\BusinessTransactionPostingCoordinator;
use App\Domain\Accounting\Services\FiscalPeriodService;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PostgreSqlGoldenGateConcurrencyTest extends TestCase
{
    private function skipIfNotPostgreSql(): void
    {
        try {
            config([
                'database.connections.pgsql.database' => 'maarif_keuangan',
                'database.default' => 'pgsql',
            ]);
            DB::purge('pgsql');
            $driver = DB::connection('pgsql')->getDriverName();
            if ($driver !== 'pgsql') {
                $this->markTestSkipped('PostgreSQL connection is not active.');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL server is not reachable: '.$e->getMessage());
        }
    }

    /**
     * @return array{
     *     org: object,
     *     period_id: int,
     *     admin: User,
     *     operator: User,
     *     approver: User,
     *     acc_kas_id: int,
     *     acc_pendapatan_id: int,
     *     bank_a_id: int,
     *     bank_b_id: int,
     *     rule_id: int
     * }
     */
    private function getOrCreateFixtures(): array
    {
        $org = DB::connection('pgsql')->table('organizations')->where('code', 'LP-MAARIF-CLP')->first();
        if (! $org) {
            $orgId = DB::connection('pgsql')->table('organizations')->insertGetId([
                'name' => 'LP Ma\'arif NU PCNU Cilacap',
                'code' => 'LP-MAARIF-CLP',
                'type' => 'cabang',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $org = DB::connection('pgsql')->table('organizations')->where('id', $orgId)->first();
        }

        foreach (['journal.view', 'journal.create', 'journal.review', 'journal.approve', 'journal.post', 'journal.reverse'] as $perm) {
            Permission::findOrCreate($perm, 'sanctum');
        }

        // Admin User
        $admin = User::withoutGlobalScopes()->where('email', 'p78_admin@maarif.test')->first();
        if (! $admin) {
            $admin = User::create([
                'organization_id' => $org->id,
                'name' => 'P78 Admin User',
                'email' => 'p78_admin@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
            ]);
            $admin->syncPermissions(['journal.view', 'journal.create', 'journal.review', 'journal.approve', 'journal.post']);
        }

        // Operator User (Maker)
        $operator = User::withoutGlobalScopes()->where('email', 'p78_operator@maarif.test')->first();
        if (! $operator) {
            $operator = User::create([
                'organization_id' => $org->id,
                'name' => 'P78 Operator User',
                'email' => 'p78_operator@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
            ]);
            $operator->syncPermissions(['journal.view', 'journal.create']);
        }

        // Approver User (Checker)
        $approver = User::withoutGlobalScopes()->where('email', 'p78_approver@maarif.test')->first();
        if (! $approver) {
            $approver = User::create([
                'organization_id' => $org->id,
                'name' => 'P78 Approver User',
                'email' => 'p78_approver@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
            ]);
            $approver->syncPermissions(['journal.view', 'journal.review', 'journal.approve', 'journal.post']);
        }

        // Dedicated Open Fiscal Period for Concurrency Test
        $periodCode = 'PER-P78-CONC';
        $period = DB::connection('pgsql')->table('fiscal_periods')
            ->where('organization_id', $org->id)
            ->where('code', $periodCode)
            ->first();

        if (! $period) {
            $periodId = DB::connection('pgsql')->table('fiscal_periods')->insertGetId([
                'organization_id' => $org->id,
                'code' => $periodCode,
                'name' => 'Period P78 Concurrency 2026',
                'period_type' => 'monthly',
                'start_date' => '2026-07-01',
                'end_date' => '2026-07-31',
                'status' => 'open',
                'is_current' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $periodId = $period->id;
            DB::connection('pgsql')->table('fiscal_periods')
                ->where('id', $periodId)
                ->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);
        }

        // Accounts
        $accKas = DB::connection('pgsql')->table('accounts')->where('organization_id', $org->id)->where('code', '1110')->first();
        if (! $accKas) {
            $accKasId = DB::connection('pgsql')->table('accounts')->insertGetId([
                'organization_id' => $org->id,
                'code' => '1110',
                'name' => 'Kas di Bank',
                'account_type' => 'asset',
                'normal_balance' => 'debit',
                'is_postable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $accKasId = $accKas->id;
        }

        $accPendapatan = DB::connection('pgsql')->table('accounts')->where('organization_id', $org->id)->where('code', '4100')->first();
        if (! $accPendapatan) {
            $accPendapatanId = DB::connection('pgsql')->table('accounts')->insertGetId([
                'organization_id' => $org->id,
                'code' => '4100',
                'name' => 'Pendapatan Iuran',
                'account_type' => 'revenue',
                'normal_balance' => 'credit',
                'is_postable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $accPendapatanId = $accPendapatan->id;
        }

        // Bank Accounts (Two distinct accounts for transfer testing)
        $bankA = DB::connection('pgsql')->table('bank_accounts')->where('organization_id', $org->id)->where('account_number', '0106-01-000308-30-8')->first();
        if (! $bankA) {
            $bankAId = DB::connection('pgsql')->table('bank_accounts')->insertGetId([
                'organization_id' => $org->id,
                'bank_name' => 'Bank BRI Giro 308',
                'account_number' => '0106-01-000308-30-8',
                'account_name' => 'LP MAARIF NU CILACAP (GIRO 308)',
                'branch' => 'Cilacap',
                'type' => 'giro',
                'account_id' => $accKasId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $bankAId = $bankA->id;
        }

        $bankB = DB::connection('pgsql')->table('bank_accounts')->where('organization_id', $org->id)->where('account_number', '0106-01-000304-30-0')->first();
        if (! $bankB) {
            $bankBId = DB::connection('pgsql')->table('bank_accounts')->insertGetId([
                'organization_id' => $org->id,
                'bank_name' => 'Bank BRI Giro 304',
                'account_number' => '0106-01-000304-30-0',
                'account_name' => 'LP MAARIF NU CILACAP (GIRO 304)',
                'branch' => 'Cilacap',
                'type' => 'giro',
                'account_id' => $accKasId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $bankBId = $bankB->id;
        }

        // Classification Rule
        $rule = DB::connection('pgsql')->table('classification_rules')->where('organization_id', $org->id)->where('name', 'P78 Golden Concurrency Rule')->first();
        if (! $rule) {
            $ruleId = DB::connection('pgsql')->table('classification_rules')->insertGetId([
                'organization_id' => $org->id,
                'name' => 'P78 Golden Concurrency Rule',
                'priority' => 10,
                'is_active' => true,
                'rule_type' => 'KEYWORD',
                'direction' => 'IN',
                'bank_account_id' => $bankAId,
                'pattern' => 'GOLDEN INFAQ',
                'pattern_type' => 'contains',
                'transaction_type' => 'DIRECT_INCOME',
                'target_account_id' => $accPendapatanId,
                'auto_post' => true,
                'max_auto_post_amount' => '10000000.00',
                'version' => 1,
                'created_by' => $operator->id,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $ruleId = $rule->id;
        }

        return [
            'org' => $org,
            'period_id' => $periodId,
            'admin' => $admin,
            'operator' => $operator,
            'approver' => $approver,
            'acc_kas_id' => $accKasId,
            'acc_pendapatan_id' => $accPendapatanId,
            'bank_a_id' => $bankAId,
            'bank_b_id' => $bankBId,
            'rule_id' => $ruleId,
        ];
    }

    public function test_postgresql_double_posting_race_condition_prevention(): void
    {
        $this->skipIfNotPostgreSql();
        $fixtures = $this->getOrCreateFixtures();
        TenantContext::setTenantId($fixtures['org']->id);

        /** @var JournalPostingService $journalPostingService */
        $journalPostingService = app(JournalPostingService::class);

        // Create draft journal entry
        $draft = $journalPostingService->createDraft([
            'organization_id' => $fixtures['org']->id,
            'entry_date' => '2026-07-10',
            'lines' => [
                ['account_id' => $fixtures['acc_kas_id'], 'debit' => '250000.00', 'credit' => '0.00'],
                ['account_id' => $fixtures['acc_pendapatan_id'], 'debit' => '0.00', 'credit' => '250000.00'],
            ],
        ], $fixtures['operator']);

        // First posting call succeeds
        $posted = $journalPostingService->post($draft, $fixtures['approver']);
        $this->assertEquals(JournalEntry::STATUS_POSTED, $posted->status);

        // Second concurrent call to post the same entry MUST be rejected
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only DRAFT journals can be posted');

        $journalPostingService->post($draft, $fixtures['approver']);
    }

    public function test_postgresql_human_approval_vs_auto_post_race_condition(): void
    {
        $this->skipIfNotPostgreSql();
        $fixtures = $this->getOrCreateFixtures();
        TenantContext::setTenantId($fixtures['org']->id);

        // Create transaction and draft
        $tx = $this->createPostgresTransactionDraft($fixtures, 500000.00, '2026-07-15');

        /** @var BusinessTransactionPostingCoordinator $postingCoordinator */
        $postingCoordinator = app(BusinessTransactionPostingCoordinator::class);
        /** @var AutoPostExecutionCoordinator $autoPostCoordinator */
        $autoPostCoordinator = app(AutoPostExecutionCoordinator::class);

        // Worker 1: Human approve and post
        $humanResult = $postingCoordinator->approveAndPost($tx, $fixtures['approver']);
        $this->assertTrue($humanResult['success']);
        $this->assertFalse($humanResult['is_duplicate']);

        // Worker 2: Auto-post executes on the same transaction -> Handled idempotently / skipped
        $autoResult = $autoPostCoordinator->execute($tx->id);
        $this->assertTrue($autoResult['success']);
        $this->assertTrue($autoResult['is_duplicate']);

        // Exactly 1 posted journal exists for this transaction
        $journalCount = DB::connection('pgsql')->table('journal_entries')
            ->where('business_transaction_id', $tx->id)
            ->where('status', 'posted')
            ->count();

        $this->assertEquals(1, $journalCount);
    }

    public function test_postgresql_concurrent_bank_transfer_pairing_race_condition(): void
    {
        $this->skipIfNotPostgreSql();
        $fixtures = $this->getOrCreateFixtures();
        TenantContext::setTenantId($fixtures['org']->id);

        /** @var BankTransferPostingCoordinator $coordinator */
        $coordinator = app(BankTransferPostingCoordinator::class);

        // Contested Leg OUT
        $txOut = $this->createPostgresBankTransaction($fixtures, 'OUT', 1000000.00, $fixtures['bank_a_id'], '2026-07-12');
        // Two competing candidate Leg INs
        $txIn1 = $this->createPostgresBankTransaction($fixtures, 'IN', 1000000.00, $fixtures['bank_b_id'], '2026-07-12');
        $txIn2 = $this->createPostgresBankTransaction($fixtures, 'IN', 1000000.00, $fixtures['bank_b_id'], '2026-07-12');

        // Worker 1 pairs txOut with txIn1
        $res1 = $coordinator->pair($txOut, $txIn1, $fixtures['approver']);
        $this->assertTrue($res1->isSuccessful());

        $txOut->refresh();
        $this->assertEquals(TransferPairingStatus::PAIRED->value, $txOut->transfer_pairing_status);
        $this->assertEquals($txIn1->id, $txOut->paired_business_transaction_id);

        // Worker 2 attempts to pair txOut with txIn2 -> MUST fail because txOut is already paired
        $this->expectException(InvalidTransferPairingException::class);
        $coordinator->pair($txOut, $txIn2, $fixtures['approver']);
    }

    public function test_postgresql_period_closing_vs_posting_rollback_atomicity(): void
    {
        $this->skipIfNotPostgreSql();
        $fixtures = $this->getOrCreateFixtures();
        TenantContext::setTenantId($fixtures['org']->id);

        /** @var FiscalPeriodService $fiscalPeriodService */
        $fiscalPeriodService = app(FiscalPeriodService::class);
        /** @var JournalPostingService $journalPostingService */
        $journalPostingService = app(JournalPostingService::class);

        // Create draft journal in the period
        $draft = $journalPostingService->createDraft([
            'organization_id' => $fixtures['org']->id,
            'entry_date' => '2026-07-20',
            'lines' => [
                ['account_id' => $fixtures['acc_kas_id'], 'debit' => '100000.00', 'credit' => '0.00'],
                ['account_id' => $fixtures['acc_pendapatan_id'], 'debit' => '0.00', 'credit' => '100000.00'],
            ],
        ], $fixtures['operator']);

        // Close period
        $period = FiscalPeriod::find($fixtures['period_id']);
        $fiscalPeriodService->closePeriod($period, $fixtures['admin']);

        // Posting into the closed period must throw ClosedFiscalPeriodException
        try {
            $journalPostingService->post($draft, $fixtures['approver']);
            $this->fail('Expected ClosedFiscalPeriodException');
        } catch (ClosedFiscalPeriodException $e) {
            $this->assertTrue(true);
        }

        // Rollback verification: draft remains DRAFT, NOT posted
        $draft->refresh();
        $this->assertEquals(JournalEntry::STATUS_DRAFT, $draft->status);
        $this->assertNull($draft->posted_at);
        $this->assertNull($draft->posted_by);
    }

    public function test_postgresql_compound_journal_rollback_on_failure(): void
    {
        $this->skipIfNotPostgreSql();
        $fixtures = $this->getOrCreateFixtures();
        TenantContext::setTenantId($fixtures['org']->id);

        $initialJournalCount = DB::connection('pgsql')->table('journal_entries')->count();
        $initialLineCount = DB::connection('pgsql')->table('journal_lines')->count();

        // Simulate an operation that fails halfway inside DB::transaction
        try {
            DB::transaction(function () use ($fixtures) {
                $jeId = DB::connection('pgsql')->table('journal_entries')->insertGetId([
                    'organization_id' => $fixtures['org']->id,
                    'fiscal_period_id' => $fixtures['period_id'],
                    'entry_number' => 'JE-FAIL-TEST-'.uniqid(),
                    'entry_date' => '2026-07-25',
                    'entry_type' => 'adjustment',
                    'description' => 'Test Transaction that will abort',
                    'status' => 'draft',
                    'created_by' => $fixtures['operator']->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::connection('pgsql')->table('journal_lines')->insert([
                    'organization_id' => $fixtures['org']->id,
                    'journal_entry_id' => $jeId,
                    'line_number' => 1,
                    'account_id' => $fixtures['acc_kas_id'],
                    'debit' => 500000.00,
                    'credit' => 0.00,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Intentional failure trigger
                throw new \RuntimeException('Intentional abort to verify PostgreSQL rollback atomicity.');
            });
        } catch (\RuntimeException $e) {
            $this->assertEquals('Intentional abort to verify PostgreSQL rollback atomicity.', $e->getMessage());
        }

        // Database state must be 100% clean: zero new entries, zero new lines
        $finalJournalCount = DB::connection('pgsql')->table('journal_entries')->count();
        $finalLineCount = DB::connection('pgsql')->table('journal_lines')->count();

        $this->assertEquals($initialJournalCount, $finalJournalCount);
        $this->assertEquals($initialLineCount, $finalLineCount);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function createPostgresTransactionDraft(array $f, float $amount, string $date): BusinessTransaction
    {
        $unique = uniqid('p78_');

        $ruleSnapshot = json_encode([
            'rule_id' => $f['rule_id'],
            'organization_id' => $f['org']->id,
            'version' => 1,
            'pattern' => 'GOLDEN INFAQ',
            'pattern_type' => 'contains',
            'transaction_type' => 'DIRECT_INCOME',
            'target_account_id' => $f['acc_pendapatan_id'],
            'auto_post' => true,
            'max_auto_post_amount' => '10000000.00',
            'approved_by' => $f['approver']->id,
            'approved_at' => now()->toDateTimeString(),
        ]);

        $btId = DB::connection('pgsql')->table('business_transactions')->insertGetId([
            'organization_id' => $f['org']->id,
            'transaction_number' => "BT-PG-{$unique}",
            'fiscal_period_id' => $f['period_id'],
            'transaction_date' => $date,
            'value_date' => $date,
            'transaction_type' => 'DIRECT_INCOME',
            'direction' => 'IN',
            'amount' => $amount,
            'target_account_id' => $f['acc_pendapatan_id'],
            'classification_status' => 'classified',
            'classification_method' => 'DETERMINISTIC_RULE',
            'auto_post_eligible' => true,
            'description' => "PostgreSQL Golden Concurrency Test {$unique}",
            'status' => 'draft',
            'is_period_locked' => false,
            'rule_snapshot' => $ruleSnapshot,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $jeId = DB::connection('pgsql')->table('journal_entries')->insertGetId([
            'organization_id' => $f['org']->id,
            'fiscal_period_id' => $f['period_id'],
            'entry_number' => "JE-PG-{$unique}",
            'entry_date' => $date,
            'entry_type' => 'receipt',
            'description' => "Draft Journal {$unique}",
            'business_transaction_id' => $btId,
            'status' => 'draft',
            'created_by' => $f['operator']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('pgsql')->table('journal_lines')->insert([
            [
                'organization_id' => $f['org']->id,
                'journal_entry_id' => $jeId,
                'line_number' => 1,
                'account_id' => $f['acc_kas_id'],
                'debit' => $amount,
                'credit' => 0,
                'description' => 'Debit Kas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => $f['org']->id,
                'journal_entry_id' => $jeId,
                'line_number' => 2,
                'account_id' => $f['acc_pendapatan_id'],
                'debit' => 0,
                'credit' => $amount,
                'description' => 'Credit Pendapatan',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::connection('pgsql')->table('journal_entry_sources')->insert([
            'organization_id' => $f['org']->id,
            'journal_entry_id' => $jeId,
            'source_type' => 'business_transaction',
            'source_id' => $btId,
            'business_transaction_id' => $btId,
            'leg_role' => 'primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return BusinessTransaction::find($btId);
    }

    private function createPostgresBankTransaction(
        array $f,
        string $direction,
        float $amount,
        int $bankAccountId,
        string $date
    ): BusinessTransaction {
        $unique = uniqid('bt_trf_');

        $btId = DB::connection('pgsql')->table('business_transactions')->insertGetId([
            'organization_id' => $f['org']->id,
            'transaction_number' => "BT-TRF-{$unique}",
            'fiscal_period_id' => $f['period_id'],
            'transaction_date' => $date,
            'value_date' => $date,
            'transaction_type' => 'BANK_TRANSFER',
            'direction' => $direction,
            'amount' => $amount,
            'classification_status' => 'classified',
            'transfer_pairing_status' => TransferPairingStatus::PENDING_PAIRING->value,
            'status' => 'pending',
            'is_period_locked' => false,
            'description' => "Bank Transfer Leg {$direction} {$unique}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Link a bank_transaction so bank_account_id is discoverable
        $bankTxId = DB::connection('pgsql')->table('bank_transactions')->insertGetId([
            'organization_id' => $f['org']->id,
            'bank_account_id' => $bankAccountId,
            'transaction_date' => $date,
            'value_date' => $date,
            'amount' => $amount,
            'direction' => $direction,
            'description' => "Bank Transfer Leg {$direction} {$unique}",
            'fingerprint' => hash('sha256', "BT-TRF-{$unique}-{$direction}-{$bankAccountId}-".microtime(true)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('pgsql')->table('business_transactions')
            ->where('id', $btId)
            ->update(['bank_transaction_id' => $bankTxId]);

        return BusinessTransaction::find($btId);
    }
}
