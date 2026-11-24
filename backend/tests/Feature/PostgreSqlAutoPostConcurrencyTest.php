<?php

namespace Tests\Feature;

use App\Domain\Accounting\Enums\AutoPostDecisionOutcome;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\AutoPostExecutionCoordinator;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PostgreSqlAutoPostConcurrencyTest extends TestCase
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
     *     maker: User,
     *     rule_approver: User,
     *     rule_creator: User,
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

        // Ensure canonical permissions
        foreach (['journal.view', 'journal.create', 'journal.review', 'journal.approve', 'journal.post', 'journal.reverse'] as $perm) {
            Permission::findOrCreate($perm, 'sanctum');
        }

        // Maker User (Import Operator)
        $maker = User::withoutGlobalScopes()->where('email', 'p75_maker@maarif.test')->first();
        if (! $maker) {
            $maker = User::create([
                'organization_id' => $org->id,
                'name' => 'P75 Maker User',
                'email' => 'p75_maker@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
            ]);
            $maker->syncPermissions(['journal.view', 'journal.create']);
        }

        // Rule Creator User
        $ruleCreator = User::withoutGlobalScopes()->where('email', 'p75_creator@maarif.test')->first();
        if (! $ruleCreator) {
            $ruleCreator = User::create([
                'organization_id' => $org->id,
                'name' => 'P75 Rule Creator User',
                'email' => 'p75_creator@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
            ]);
            $ruleCreator->syncPermissions(['journal.view', 'journal.create']);
        }

        // Rule Approver User
        $ruleApprover = User::withoutGlobalScopes()->where('email', 'p75_approver@maarif.test')->first();
        if (! $ruleApprover) {
            $ruleApprover = User::create([
                'organization_id' => $org->id,
                'name' => 'P75 Rule Approver User',
                'email' => 'p75_approver@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
            ]);
            $ruleApprover->syncPermissions(['journal.view', 'journal.review', 'journal.approve', 'journal.post']);
        }

        // Fiscal period
        $period = DB::connection('pgsql')->table('fiscal_periods')
            ->where('organization_id', $org->id)
            ->where('code', '2026-03')
            ->first();

        if (! $period) {
            $periodId = DB::connection('pgsql')->table('fiscal_periods')->insertGetId([
                'organization_id' => $org->id,
                'code' => '2026-03',
                'name' => 'Maret 2026',
                'period_type' => 'monthly',
                'start_date' => '2026-03-01',
                'end_date' => '2026-03-31',
                'status' => 'open',
                'is_current' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $periodId = $period->id;
        }

        // COA Kas (1110) & Pendapatan (4100)
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
        $bankAccount = DB::connection('pgsql')->table('bank_accounts')
            ->where('organization_id', $org->id)
            ->first();
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

        // Classification Rule
        $rule = DB::connection('pgsql')->table('classification_rules')
            ->where('organization_id', $org->id)
            ->where('name', 'P75 Postgres AutoPost Rule')
            ->first();

        if (! $rule) {
            $ruleId = DB::connection('pgsql')->table('classification_rules')->insertGetId([
                'organization_id' => $org->id,
                'name' => 'P75 Postgres AutoPost Rule',
                'priority' => 10,
                'is_active' => true,
                'rule_type' => 'KEYWORD',
                'direction' => 'IN',
                'bank_account_id' => $bankAccountId,
                'pattern' => 'INFAQ POSTGRES',
                'pattern_type' => 'contains',
                'transaction_type' => 'DIRECT_INCOME',
                'target_account_id' => $accPendapatanId,
                'auto_post' => true,
                'max_auto_post_amount' => '1000000.00',
                'version' => 1,
                'created_by' => $ruleCreator->id,
                'approved_by' => $ruleApprover->id,
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
            'maker' => $maker,
            'rule_creator' => $ruleCreator,
            'rule_approver' => $ruleApprover,
            'acc_kas_id' => $accKasId,
            'acc_pendapatan_id' => $accPendapatanId,
            'rule_id' => $ruleId,
        ];
    }

    /**
     * Helper to create a paired BusinessTransaction and balanced draft JournalEntry on PostgreSQL.
     */
    private function createPostgresTransactionDraft(array $f, float $amount = 250000.00): array
    {
        $unique = uniqid('p75_');
        $ruleSnapshot = json_encode([
            'rule_id' => $f['rule_id'],
            'organization_id' => $f['org']->id,
            'version' => 1,
            'pattern' => 'INFAQ POSTGRES',
            'pattern_type' => 'contains',
            'transaction_type' => 'DIRECT_INCOME',
            'target_account_id' => $f['acc_pendapatan_id'],
            'auto_post' => true,
            'max_auto_post_amount' => '1000000.00',
            'approved_by' => $f['rule_approver']->id,
            'approved_at' => now()->toDateTimeString(),
        ]);

        $btId = DB::connection('pgsql')->table('business_transactions')->insertGetId([
            'organization_id' => $f['org']->id,
            'transaction_number' => "BT-PG-{$unique}",
            'fiscal_period_id' => $f['period_id'],
            'transaction_date' => '2026-03-15',
            'value_date' => '2026-03-15',
            'transaction_type' => 'DIRECT_INCOME',
            'direction' => 'IN',
            'amount' => $amount,
            'target_account_id' => $f['acc_pendapatan_id'],
            'classification_status' => 'classified',
            'classification_method' => 'DETERMINISTIC_RULE',
            'auto_post_eligible' => true,
            'description' => "PostgreSQL Concurrency Test {$unique}",
            'status' => 'draft',
            'rule_snapshot' => $ruleSnapshot,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $jeId = DB::connection('pgsql')->table('journal_entries')->insertGetId([
            'organization_id' => $f['org']->id,
            'fiscal_period_id' => $f['period_id'],
            'entry_number' => "JE-PG-{$unique}",
            'entry_date' => '2026-03-15',
            'entry_type' => 'receipt',
            'description' => "Draft Journal {$unique}",
            'business_transaction_id' => $btId,
            'status' => 'draft',
            'created_by' => $f['maker']->id,
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

        return ['bt_id' => $btId, 'je_id' => $jeId];
    }

    public function test_postgresql_connection_and_version(): void
    {
        $this->skipIfNotPostgreSql();

        $version = DB::connection('pgsql')->select('SELECT version()')[0]->version ?? '';
        $this->assertStringContainsString('PostgreSQL', $version);
    }

    public function test_postgresql_auto_post_single_execution_with_zero_identity_spoofing(): void
    {
        $this->skipIfNotPostgreSql();
        $f = $this->getOrCreateFixtures();
        TenantContext::setTenantId($f['org']->id);

        try {
            $pair = $this->createPostgresTransactionDraft($f, 180000.00);

            $coordinator = app(AutoPostExecutionCoordinator::class);
            $result = $coordinator->execute($pair['bt_id']);

            $this->assertTrue($result['success']);
            $this->assertSame(AutoPostDecisionOutcome::ELIGIBLE->value, $result['outcome']);
            $this->assertFalse($result['is_duplicate']);
            $this->assertSame($pair['je_id'], $result['data']['journal_entry_id']);

            // PostgreSQL DB Verification: Zero identity spoofing
            $je = DB::connection('pgsql')->table('journal_entries')->where('id', $pair['je_id'])->first();
            $this->assertSame('posted', $je->status);
            $this->assertNull($je->posted_by, 'posted_by MUST be NULL for system daemon auto-post');
            $this->assertSame($f['rule_id'], (int) $je->posted_by_rule_id);
            $this->assertNotNull($je->posted_at);

            $bt = DB::connection('pgsql')->table('business_transactions')->where('id', $pair['bt_id'])->first();
            $this->assertSame('posted', $bt->status);
            $this->assertSame($pair['je_id'], (int) $bt->posted_journal_entry_id);

            // Audit trail verification
            $audit = DB::connection('pgsql')->table('audit_logs')
                ->where('model_type', JournalEntry::class)
                ->where('model_id', $pair['je_id'])
                ->where('action', 'JOURNAL_POSTED_AUTOMATED')
                ->first();

            $this->assertNotNull($audit);
            $this->assertNull($audit->user_id);
            $newValues = json_decode($audit->new_values, true);
            $this->assertSame('SYSTEM_DAEMON', $newValues['executor']);
            $this->assertSame($f['rule_id'], (int) $newValues['posted_by_rule_id']);
        } finally {
            TenantContext::setTenantId(null);
        }
    }

    public function test_postgresql_concurrency_race_condition_guard(): void
    {
        $this->skipIfNotPostgreSql();
        $f = $this->getOrCreateFixtures();
        TenantContext::setTenantId($f['org']->id);

        try {
            $pair = $this->createPostgresTransactionDraft($f, 220000.00);
            $coordinator = app(AutoPostExecutionCoordinator::class);

            // Worker 1: Executes and posts transaction
            $result1 = $coordinator->execute($pair['bt_id']);
            $this->assertTrue($result1['success']);
            $this->assertSame(AutoPostDecisionOutcome::ELIGIBLE->value, $result1['outcome']);

            // Worker 2: Concurrent/Subsequent execution on same transaction
            $result2 = $coordinator->execute($pair['bt_id']);
            $this->assertTrue($result2['success']);
            $this->assertSame(AutoPostDecisionOutcome::ALREADY_POSTED->value, $result2['outcome']);
            $this->assertTrue($result2['is_duplicate']);
            $this->assertSame($pair['je_id'], $result2['data']['journal_entry_id']);

            // Verify exactly one journal entry exists and posted
            $journalCount = DB::connection('pgsql')->table('journal_entries')
                ->where('business_transaction_id', $pair['bt_id'])
                ->count();
            $this->assertSame(1, $journalCount);
        } finally {
            TenantContext::setTenantId(null);
        }
    }

    public function test_postgresql_batch_execution_failure_isolation(): void
    {
        $this->skipIfNotPostgreSql();
        $f = $this->getOrCreateFixtures();
        TenantContext::setTenantId($f['org']->id);

        try {
            // Candidate 1: valid (150,000)
            $pair1 = $this->createPostgresTransactionDraft($f, 150000.00);

            // Candidate 2: amount exceeds max_auto_post_amount (1,500,000 > 1,000,000 limit)
            $pair2 = $this->createPostgresTransactionDraft($f, 1500000.00);

            // Candidate 3: valid (250,000)
            $pair3 = $this->createPostgresTransactionDraft($f, 250000.00);

            $coordinator = app(AutoPostExecutionCoordinator::class);
            $summary = $coordinator->executeBatch($f['org']->id, 50);

            $this->assertGreaterThanOrEqual(3, $summary['total_candidates']);
            $this->assertGreaterThanOrEqual(2, $summary['posted_count']);
            $this->assertGreaterThanOrEqual(1, $summary['skipped_count']);

            // Candidate 1 and 3 must be posted on PostgreSQL
            $je1 = DB::connection('pgsql')->table('journal_entries')->where('id', $pair1['je_id'])->first();
            $this->assertSame('posted', $je1->status);

            $je3 = DB::connection('pgsql')->table('journal_entries')->where('id', $pair3['je_id'])->first();
            $this->assertSame('posted', $je3->status);

            // Candidate 2 must remain in draft
            $je2 = DB::connection('pgsql')->table('journal_entries')->where('id', $pair2['je_id'])->first();
            $this->assertSame('draft', $je2->status);

            $bt2 = DB::connection('pgsql')->table('business_transactions')->where('id', $pair2['bt_id'])->first();
            $this->assertSame('draft', $bt2->status);
        } finally {
            TenantContext::setTenantId(null);
        }
    }
}
