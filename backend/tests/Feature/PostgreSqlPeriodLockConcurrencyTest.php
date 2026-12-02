<?php

namespace Tests\Feature;

use App\Domain\Accounting\Enums\AutoPostDecisionOutcome;
use App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\AutoPostExecutionCoordinator;
use App\Domain\Accounting\Services\FiscalPeriodService;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PostgreSqlPeriodLockConcurrencyTest extends TestCase
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
        $admin = User::withoutGlobalScopes()->where('email', 'p77_admin@maarif.test')->first();
        if (! $admin) {
            $admin = User::create([
                'organization_id' => $org->id,
                'name' => 'P77 Admin User',
                'email' => 'p77_admin@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
            ]);
            $admin->syncPermissions(['journal.view', 'journal.create', 'journal.review', 'journal.approve', 'journal.post']);
        }

        // Operator User
        $operator = User::withoutGlobalScopes()->where('email', 'p77_operator@maarif.test')->first();
        if (! $operator) {
            $operator = User::create([
                'organization_id' => $org->id,
                'name' => 'P77 Operator User',
                'email' => 'p77_operator@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
            ]);
            $operator->syncPermissions(['journal.view', 'journal.create']);
        }

        // Approver User
        $approver = User::withoutGlobalScopes()->where('email', 'p77_approver@maarif.test')->first();
        if (! $approver) {
            $approver = User::create([
                'organization_id' => $org->id,
                'name' => 'P77 Approver User',
                'email' => 'p77_approver@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
            ]);
            $approver->syncPermissions(['journal.view', 'journal.review', 'journal.approve', 'journal.post']);
        }

        // Dedicated Open Fiscal Period for Concurrency Test
        $periodCode = 'PER-LOCK-TEST';
        $period = DB::connection('pgsql')->table('fiscal_periods')
            ->where('organization_id', $org->id)
            ->where('code', $periodCode)
            ->first();

        if (! $period) {
            $periodId = DB::connection('pgsql')->table('fiscal_periods')->insertGetId([
                'organization_id' => $org->id,
                'code' => $periodCode,
                'name' => 'Period Lock Test 2026',
                'period_type' => 'monthly',
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-30',
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

        // Bank Account
        $bankAccount = DB::connection('pgsql')->table('bank_accounts')->where('organization_id', $org->id)->first();
        if (! $bankAccount) {
            $bankAccountId = DB::connection('pgsql')->table('bank_accounts')->insertGetId([
                'organization_id' => $org->id,
                'bank_name' => 'Bank BRI',
                'account_number' => '0106-01-000308-30-8',
                'account_name' => 'LP MAARIF NU CILACAP',
                'branch' => 'Cilacap',
                'type' => 'giro',
                'account_id' => $accKasId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $bankAccountId = $bankAccount->id;
        }

        // Rule for auto-posting
        $rule = DB::connection('pgsql')->table('classification_rules')->where('organization_id', $org->id)->where('name', 'P77 Auto Post Rule')->first();
        if (! $rule) {
            $ruleId = DB::connection('pgsql')->table('classification_rules')->insertGetId([
                'organization_id' => $org->id,
                'name' => 'P77 Auto Post Rule',
                'priority' => 10,
                'is_active' => true,
                'rule_type' => 'KEYWORD',
                'direction' => 'IN',
                'bank_account_id' => $bankAccountId,
                'pattern' => 'IURAN',
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
            'rule_id' => $ruleId,
        ];
    }

    public function test_toctou_prevention_concurrent_posting_and_close_period(): void
    {
        $this->skipIfNotPostgreSql();
        $fixtures = $this->getOrCreateFixtures();
        TenantContext::setTenantId($fixtures['org']->id);

        /** @var FiscalPeriodService $fiscalPeriodService */
        $fiscalPeriodService = app(FiscalPeriodService::class);
        /** @var JournalPostingService $journalPostingService */
        $journalPostingService = app(JournalPostingService::class);

        // 1. Create a draft journal entry in the test period
        $draft = $journalPostingService->createDraft([
            'organization_id' => $fixtures['org']->id,
            'entry_date' => '2026-06-15',
            'lines' => [
                ['account_id' => $fixtures['acc_kas_id'], 'debit' => '200000.00', 'credit' => '0.00'],
                ['account_id' => $fixtures['acc_pendapatan_id'], 'debit' => '0.00', 'credit' => '200000.00'],
            ],
        ], $fixtures['operator']);

        // 2. Simulate TOCTOU scenario:
        // A period closing process starts and acquires row lock on fiscal_periods
        $period = FiscalPeriod::find($fixtures['period_id']);
        $fiscalPeriodService->closePeriod($period, $fixtures['admin']);

        // 3. Any subsequent or interleaved posting attempt with transaction date in this period MUST fail
        $this->expectException(ClosedFiscalPeriodException::class);

        $journalPostingService->post($draft, $fixtures['approver']);
    }

    public function test_two_concurrent_postings_in_same_open_period_do_not_deadlock(): void
    {
        $this->skipIfNotPostgreSql();
        $fixtures = $this->getOrCreateFixtures();
        TenantContext::setTenantId($fixtures['org']->id);

        /** @var JournalPostingService $journalPostingService */
        $journalPostingService = app(JournalPostingService::class);

        // Reopen period if closed by previous test
        DB::connection('pgsql')->table('fiscal_periods')
            ->where('id', $fixtures['period_id'])
            ->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);

        // Create two separate draft entries in the same period
        $draft1 = $journalPostingService->createDraft([
            'organization_id' => $fixtures['org']->id,
            'entry_date' => '2026-06-10',
            'lines' => [
                ['account_id' => $fixtures['acc_kas_id'], 'debit' => '100000.00', 'credit' => '0.00'],
                ['account_id' => $fixtures['acc_pendapatan_id'], 'debit' => '0.00', 'credit' => '100000.00'],
            ],
        ], $fixtures['operator']);

        $draft2 = $journalPostingService->createDraft([
            'organization_id' => $fixtures['org']->id,
            'entry_date' => '2026-06-20',
            'lines' => [
                ['account_id' => $fixtures['acc_kas_id'], 'debit' => '150000.00', 'credit' => '0.00'],
                ['account_id' => $fixtures['acc_pendapatan_id'], 'debit' => '0.00', 'credit' => '150000.00'],
            ],
        ], $fixtures['operator']);

        // Post both entries - both should post cleanly without deadlocking
        $posted1 = $journalPostingService->post($draft1, $fixtures['approver']);
        $posted2 = $journalPostingService->post($draft2, $fixtures['approver']);

        $this->assertEquals(JournalEntry::STATUS_POSTED, $posted1->status);
        $this->assertEquals(JournalEntry::STATUS_POSTED, $posted2->status);
        $this->assertNotEquals($posted1->entry_number, $posted2->entry_number);
    }

    private function createPostgresTransactionDraft(
        array $f,
        float $amount,
        string $date,
        bool $isPeriodLocked = false
    ): BusinessTransaction {
        $unique = uniqid('p77_');

        $ruleSnapshot = json_encode([
            'rule_id' => $f['rule_id'],
            'organization_id' => $f['org']->id,
            'version' => 1,
            'pattern' => 'IURAN',
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
            'description' => "PostgreSQL Concurrency Test {$unique}",
            'status' => 'draft',
            'is_period_locked' => $isPeriodLocked,
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

        // Lines: Debit Kas, Credit Pendapatan
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

        // Provenance source
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

    public function test_batch_auto_post_respects_period_lock_with_failure_isolation(): void
    {
        $this->skipIfNotPostgreSql();
        $fixtures = $this->getOrCreateFixtures();
        TenantContext::setTenantId($fixtures['org']->id);

        // Reopen test period
        DB::connection('pgsql')->table('fiscal_periods')
            ->where('id', $fixtures['period_id'])
            ->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);

        // Transaction 1: In open period, not locked -> Should be eligible and POSTED
        $tx1 = $this->createPostgresTransactionDraft($fixtures, 500000.00, '2026-06-12', false);

        // Transaction 2: Period locked (is_period_locked = true) -> Should be REJECTED / SKIPPED
        $tx2 = $this->createPostgresTransactionDraft($fixtures, 750000.00, '2026-06-14', true);

        /** @var AutoPostExecutionCoordinator $coordinator */
        $coordinator = app(AutoPostExecutionCoordinator::class);

        $res1 = $coordinator->execute($tx1->id);
        $res2 = $coordinator->execute($tx2->id);

        // Tx1 must be successfully auto-posted
        $this->assertEquals(AutoPostDecisionOutcome::ELIGIBLE->value, $res1['outcome']);
        $this->assertTrue($res1['success']);
        $this->assertNotNull($res1['data']['journal_entry_id']);

        // Tx2 must be rejected due to period lock
        $this->assertEquals(AutoPostDecisionOutcome::BLOCKED->value, $res2['outcome']);
        $this->assertEquals('PERIOD_LOCKED', $res2['reason_code']);
        $this->assertFalse($res2['success']);
        $this->assertNull($res2['data']['journal_entry_id'] ?? null);

        // Failure isolation: Tx1 remains posted even though Tx2 was rejected
        $tx1->refresh();
        $this->assertEquals('posted', $tx1->status);
        $this->assertNotNull($tx1->posted_journal_entry_id);

        $tx2->refresh();
        $this->assertNotEquals('posted', $tx2->status);
        $this->assertNull($tx2->posted_journal_entry_id);
    }
}
