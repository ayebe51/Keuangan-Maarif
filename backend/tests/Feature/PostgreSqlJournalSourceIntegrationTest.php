<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgreSqlJournalSourceIntegrationTest extends TestCase
{
    private function skipIfNotPostgreSql(): void
    {
        try {
            config(['database.connections.pgsql.database' => 'maarif_keuangan']);
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
     * @return array{org: object, period_id: int, je_id: int, bt_id: int}
     */
    private function getOrCreateFixtures(): array
    {
        $org = DB::connection('pgsql')->table('organizations')->first();
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

        $je = DB::connection('pgsql')->table('journal_entries')
            ->where('organization_id', $org->id)
            ->where('entry_number', 'JE-PGSQL-TEST')
            ->first();

        if (! $je) {
            $jeId = DB::connection('pgsql')->table('journal_entries')->insertGetId([
                'organization_id' => $org->id,
                'fiscal_period_id' => $periodId,
                'entry_number' => 'JE-PGSQL-TEST',
                'entry_date' => '2026-03-15',
                'entry_type' => 'receipt',
                'description' => 'Test Journal Entry for PostgreSQL Integration',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $jeId = $je->id;
        }

        $bt = DB::connection('pgsql')->table('business_transactions')
            ->where('organization_id', $org->id)
            ->where('transaction_number', 'BT-PGSQL-TEST')
            ->first();

        if (! $bt) {
            $btId = DB::connection('pgsql')->table('business_transactions')->insertGetId([
                'organization_id' => $org->id,
                'transaction_number' => 'BT-PGSQL-TEST',
                'fiscal_period_id' => $periodId,
                'transaction_date' => '2026-03-15',
                'transaction_type' => 'DIRECT_INCOME',
                'direction' => 'IN',
                'amount' => '1000000.00',
                'description' => 'Test Business Transaction for PostgreSQL Integration',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $btId = $bt->id;
        }

        return [
            'org' => $org,
            'period_id' => $periodId,
            'je_id' => $jeId,
            'bt_id' => $btId,
        ];
    }

    public function test_postgresql_ddl_and_check_constraints_exist(): void
    {
        $this->skipIfNotPostgreSql();

        $constraints = DB::connection('pgsql')->select(
            "SELECT conname, contype, pg_get_constraintdef(oid) as def
             FROM pg_constraint
             WHERE conrelid = 'journal_entry_sources'::regclass"
        );

        $constraintNames = array_map(fn ($c) => $c->conname, $constraints);

        $this->assertContains('chk_je_sources_leg_role', $constraintNames);
        $this->assertContains('chk_je_sources_type', $constraintNames);
        $this->assertContains('je_sources_org_type_id_unique', $constraintNames);
        $this->assertContains('journal_entry_sources_business_transaction_id_foreign', $constraintNames);
        $this->assertContains('journal_entry_sources_journal_entry_id_foreign', $constraintNames);
    }

    public function test_postgresql_check_constraint_rejects_invalid_leg_role(): void
    {
        $this->skipIfNotPostgreSql();
        $fixtures = $this->getOrCreateFixtures();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('chk_je_sources_leg_role');

        DB::connection('pgsql')->table('journal_entry_sources')->insert([
            'organization_id' => $fixtures['org']->id,
            'journal_entry_id' => $fixtures['je_id'],
            'source_type' => 'business_transaction',
            'source_id' => $fixtures['bt_id'],
            'business_transaction_id' => $fixtures['bt_id'],
            'leg_role' => 'INVALID_ROLE', // Rejected by chk_je_sources_leg_role
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_postgresql_unique_source_constraint_enforced(): void
    {
        $this->skipIfNotPostgreSql();
        $fixtures = $this->getOrCreateFixtures();

        // Clean up any existing test link
        DB::connection('pgsql')->table('journal_entry_sources')
            ->where('organization_id', $fixtures['org']->id)
            ->where('source_id', $fixtures['bt_id'])
            ->delete();

        // Insert first link
        DB::connection('pgsql')->table('journal_entry_sources')->insert([
            'organization_id' => $fixtures['org']->id,
            'journal_entry_id' => $fixtures['je_id'],
            'source_type' => 'business_transaction',
            'source_id' => $fixtures['bt_id'],
            'business_transaction_id' => $fixtures['bt_id'],
            'leg_role' => 'primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempt duplicate link with same organization_id, source_type, source_id
        try {
            DB::connection('pgsql')->table('journal_entry_sources')->insert([
                'organization_id' => $fixtures['org']->id,
                'journal_entry_id' => $fixtures['je_id'],
                'source_type' => 'business_transaction',
                'source_id' => $fixtures['bt_id'],
                'business_transaction_id' => $fixtures['bt_id'],
                'leg_role' => 'secondary',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected QueryException for unique constraint violation was not thrown.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('je_sources_org_type_id_unique', $e->getMessage());
        } finally {
            // Clean up
            DB::connection('pgsql')->table('journal_entry_sources')
                ->where('organization_id', $fixtures['org']->id)
                ->where('source_id', $fixtures['bt_id'])
                ->delete();
        }
    }

    public function test_postgresql_row_locking_and_rollback(): void
    {
        $this->skipIfNotPostgreSql();
        $fixtures = $this->getOrCreateFixtures();

        $initialSourcesCount = DB::connection('pgsql')->table('journal_entry_sources')->count();

        try {
            DB::connection('pgsql')->transaction(function () use ($fixtures) {
                // Acquire row lock in PostgreSQL
                $locked = DB::connection('pgsql')->table('business_transactions')
                    ->where('id', $fixtures['bt_id'])
                    ->lockForUpdate()
                    ->first();

                $this->assertNotNull($locked);

                DB::connection('pgsql')->table('journal_entry_sources')->insert([
                    'organization_id' => $fixtures['org']->id,
                    'journal_entry_id' => $fixtures['je_id'],
                    'source_type' => 'business_transaction',
                    'source_id' => $fixtures['bt_id'],
                    'business_transaction_id' => $fixtures['bt_id'],
                    'leg_role' => 'primary',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Simulate catastrophic abort
                throw new \RuntimeException('PostgreSQL transaction rollback test');
            });
        } catch (\RuntimeException $e) {
            // Expected rollback
        }

        $this->assertSame($initialSourcesCount, DB::connection('pgsql')->table('journal_entry_sources')->count());
    }

    protected function tearDown(): void
    {
        try {
            config(['database.connections.pgsql.database' => 'maarif_keuangan']);
            DB::purge('pgsql');
            DB::connection('pgsql')->table('journal_entry_sources')
                ->where('source_type', 'business_transaction')
                ->where('leg_role', 'primary')
                ->delete();
            DB::connection('pgsql')->table('business_transactions')
                ->where('transaction_number', 'BT-PGSQL-TEST')
                ->delete();
            DB::connection('pgsql')->table('journal_entries')
                ->where('entry_number', 'JE-PGSQL-TEST')
                ->delete();
        } catch (\Throwable) {
            // Ignore teardown errors
        }

        parent::tearDown();
    }
}
