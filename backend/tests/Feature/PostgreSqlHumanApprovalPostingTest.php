<?php

namespace Tests\Feature;

use App\Domain\Accounting\Exceptions\SegregationOfDutiesException;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\BusinessTransactionPostingCoordinator;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PostgreSqlHumanApprovalPostingTest extends TestCase
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
     *     approver: User,
     *     admin: User,
     *     acc_kas_id: int,
     *     acc_pendapatan_id: int
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

        // Permissions ensure
        foreach (['journal.view', 'journal.create', 'journal.review', 'journal.approve', 'journal.post', 'journal.reverse'] as $perm) {
            Permission::findOrCreate($perm, 'sanctum');
        }

        // Maker User
        $maker = User::withoutGlobalScopes()->where('email', 'p74_maker@maarif.test')->first();
        if (! $maker) {
            $maker = User::create([
                'organization_id' => $org->id,
                'name' => 'P74 Maker User',
                'email' => 'p74_maker@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
            ]);
            $maker->syncPermissions(['journal.view', 'journal.create']);
        }

        // Approver User
        $approver = User::withoutGlobalScopes()->where('email', 'p74_approver@maarif.test')->first();
        if (! $approver) {
            $approver = User::create([
                'organization_id' => $org->id,
                'name' => 'P74 Approver User',
                'email' => 'p74_approver@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
            ]);
            $approver->syncPermissions(['journal.view', 'journal.review', 'journal.approve', 'journal.post']);
        }

        // Admin User (Reverser)
        $admin = User::withoutGlobalScopes()->where('email', 'p74_admin@maarif.test')->first();
        if (! $admin) {
            $admin = User::create([
                'organization_id' => $org->id,
                'name' => 'P74 Admin User',
                'email' => 'p74_admin@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
            ]);
            $admin->syncPermissions(['journal.view', 'journal.review', 'journal.approve', 'journal.post', 'journal.reverse']);
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

        return [
            'org' => $org,
            'period_id' => $periodId,
            'maker' => $maker,
            'approver' => $approver,
            'admin' => $admin,
            'acc_kas_id' => $accKasId,
            'acc_pendapatan_id' => $accPendapatanId,
        ];
    }

    /**
     * Helper to create a paired BusinessTransaction and draft JournalEntry on PostgreSQL.
     */
    private function createPostgresTransactionDraft(array $f, float $amount = 750000.00): array
    {
        $unique = uniqid('p74_');
        $btId = DB::connection('pgsql')->table('business_transactions')->insertGetId([
            'organization_id' => $f['org']->id,
            'transaction_number' => "BT-PG-{$unique}",
            'fiscal_period_id' => $f['period_id'],
            'transaction_date' => '2026-03-15',
            'transaction_type' => 'DIRECT_INCOME',
            'direction' => 'IN',
            'amount' => $amount,
            'target_account_id' => $f['acc_pendapatan_id'],
            'classification_status' => 'classified',
            'description' => "PostgreSQL Human Posting Test {$unique}",
            'status' => 'draft',
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

        // Junction source
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

        return [
            'bt' => BusinessTransaction::withoutGlobalScopes()->find($btId),
            'je' => JournalEntry::withoutGlobalScopes()->find($jeId),
        ];
    }

    public function test_postgresql_pessimistic_locking_and_idempotent_repost(): void
    {
        $this->skipIfNotPostgreSql();
        $f = $this->getOrCreateFixtures();
        TenantContext::setTenantId($f['org']->id);

        $fixtures = $this->createPostgresTransactionDraft($f, 850000.00);
        $tx = $fixtures['bt'];
        $je = $fixtures['je'];

        $coordinator = app(BusinessTransactionPostingCoordinator::class);

        // 1. Worker A posts the transaction
        $resA = $coordinator->approveAndPost($tx, $f['approver']);
        $this->assertTrue($resA['success']);
        $this->assertFalse($resA['is_duplicate']);
        $this->assertSame($je->id, $resA['data']['journal_entry_id']);

        // Verify status in PostgreSQL
        $dbTx = DB::connection('pgsql')->table('business_transactions')->where('id', $tx->id)->first();
        $this->assertSame('posted', $dbTx->status);
        $this->assertSame($je->id, (int) $dbTx->posted_journal_entry_id);
        $this->assertSame($f['approver']->id, (int) $dbTx->posted_by);

        // 2. Worker B re-posts the same transaction (Concurrency / Replay simulation)
        $resB = $coordinator->approveAndPost($tx, $f['approver']);
        $this->assertTrue($resB['success']);
        $this->assertTrue($resB['is_duplicate']);
        $this->assertSame('Transaksi ini telah diposting sebelumnya ke buku besar.', $resB['message']);
        $this->assertSame($je->id, $resB['data']['journal_entry_id']);

        // Verify audit log exists on PostgreSQL
        $audit = DB::connection('pgsql')->table('audit_logs')
            ->where('model_type', BusinessTransaction::class)
            ->where('model_id', $tx->id)
            ->where('action', 'JOURNAL_POSTED_MANUAL')
            ->first();
        $this->assertNotNull($audit);

        // Cleanup
        DB::connection('pgsql')->table('journal_entry_sources')->where('business_transaction_id', $tx->id)->delete();
        DB::connection('pgsql')->table('journal_lines')->where('journal_entry_id', $je->id)->delete();
        DB::connection('pgsql')->table('journal_entries')->where('id', $je->id)->delete();
        DB::connection('pgsql')->table('business_transactions')->where('id', $tx->id)->delete();
    }

    public function test_postgresql_segregation_of_duties_enforcement(): void
    {
        $this->skipIfNotPostgreSql();
        $f = $this->getOrCreateFixtures();
        TenantContext::setTenantId($f['org']->id);

        $fixtures = $this->createPostgresTransactionDraft($f, 500000.00);
        $tx = $fixtures['bt'];
        $je = $fixtures['je'];

        // Give maker permissions so RBAC passes but SoD catches Maker == Checker
        $f['maker']->givePermissionTo(['journal.approve', 'journal.post']);

        $coordinator = app(BusinessTransactionPostingCoordinator::class);

        $this->expectException(SegregationOfDutiesException::class);

        try {
            $coordinator->approveAndPost($tx, $f['maker']);
        } finally {
            // Verify status remains draft on PostgreSQL
            $dbTx = DB::connection('pgsql')->table('business_transactions')->where('id', $tx->id)->first();
            $this->assertSame('draft', $dbTx->status);

            // Cleanup
            DB::connection('pgsql')->table('journal_entry_sources')->where('business_transaction_id', $tx->id)->delete();
            DB::connection('pgsql')->table('journal_lines')->where('journal_entry_id', $je->id)->delete();
            DB::connection('pgsql')->table('journal_entries')->where('id', $je->id)->delete();
            DB::connection('pgsql')->table('business_transactions')->where('id', $tx->id)->delete();
        }
    }

    public function test_postgresql_reversal_creates_mirrored_entry_and_records_audit(): void
    {
        $this->skipIfNotPostgreSql();
        $f = $this->getOrCreateFixtures();
        TenantContext::setTenantId($f['org']->id);

        $fixtures = $this->createPostgresTransactionDraft($f, 1200000.00);
        $tx = $fixtures['bt'];
        $je = $fixtures['je'];

        $coordinator = app(BusinessTransactionPostingCoordinator::class);

        // 1. Approve and post
        $coordinator->approveAndPost($tx, $f['approver']);

        // 2. Reverse via Admin
        $revRes = $coordinator->reverse($tx, $f['admin'], 'Koreksi PostgreSQL Reversal');
        $this->assertTrue($revRes['success']);

        $reversalJeId = $revRes['data']['reversal_journal_entry_id'];

        // Verify on PostgreSQL
        $dbTx = DB::connection('pgsql')->table('business_transactions')->where('id', $tx->id)->first();
        $this->assertSame('reversed', $dbTx->status);

        $originalJe = DB::connection('pgsql')->table('journal_entries')->where('id', $je->id)->first();
        $this->assertSame('reversed', $originalJe->status);
        $this->assertSame($reversalJeId, (int) $originalJe->reversed_entry_id);

        // Verify mirrored lines on reversal entry
        $reversalLines = DB::connection('pgsql')->table('journal_lines')
            ->where('journal_entry_id', $reversalJeId)
            ->orderBy('line_number')
            ->get();

        $this->assertCount(2, $reversalLines);
        // Line 1: Credit Kas (was Debit)
        $this->assertEquals(0, (float) $reversalLines[0]->debit);
        $this->assertEquals(1200000.00, (float) $reversalLines[0]->credit);
        // Line 2: Debit Pendapatan (was Credit)
        $this->assertEquals(1200000.00, (float) $reversalLines[1]->debit);
        $this->assertEquals(0, (float) $reversalLines[1]->credit);

        // Cleanup
        DB::connection('pgsql')->table('journal_entry_sources')->where('business_transaction_id', $tx->id)->delete();
        DB::connection('pgsql')->table('journal_lines')->whereIn('journal_entry_id', [$je->id, $reversalJeId])->delete();
        DB::connection('pgsql')->table('journal_entries')->whereIn('id', [$je->id, $reversalJeId])->delete();
        DB::connection('pgsql')->table('business_transactions')->where('id', $tx->id)->delete();
    }

    public function test_postgresql_rejection_cleans_up_draft_sources_and_records_error(): void
    {
        $this->skipIfNotPostgreSql();
        $f = $this->getOrCreateFixtures();
        TenantContext::setTenantId($f['org']->id);

        $fixtures = $this->createPostgresTransactionDraft($f, 350000.00);
        $tx = $fixtures['bt'];
        $je = $fixtures['je'];

        $coordinator = app(BusinessTransactionPostingCoordinator::class);

        // Reject
        $res = $coordinator->reject($tx, $f['approver'], 'Rejection test PostgreSQL');
        $this->assertTrue($res['success']);

        // Verify in PostgreSQL
        $dbTx = DB::connection('pgsql')->table('business_transactions')->where('id', $tx->id)->first();
        $this->assertSame('rejected', $dbTx->status);
        $this->assertNotNull($dbTx->posting_error);

        $postingError = json_decode($dbTx->posting_error, true);
        $this->assertSame('HUMAN_REJECTED', $postingError['error_code']);
        $this->assertSame('Rejection test PostgreSQL', $postingError['message']);

        // Verify draft and sources are cleaned up
        $this->assertNull(DB::connection('pgsql')->table('journal_entries')->where('id', $je->id)->first());
        $this->assertNull(DB::connection('pgsql')->table('journal_entry_sources')->where('journal_entry_id', $je->id)->first());

        // Cleanup
        DB::connection('pgsql')->table('business_transactions')->where('id', $tx->id)->delete();
    }
}
