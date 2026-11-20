<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgreSqlAutomationPostingIntegrationTest extends TestCase
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
     * @return array{org: object, period_id: int, user_id: int, approver_id: int, rule_id: int, je_id: int, bt_id: int}
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

        $user = DB::connection('pgsql')->table('users')->where('email', 'p73_maker@maarif.test')->first();
        if (! $user) {
            $userId = DB::connection('pgsql')->table('users')->insertGetId([
                'organization_id' => $org->id,
                'name' => 'P73 Maker User',
                'email' => 'p73_maker@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $user = DB::connection('pgsql')->table('users')->where('id', $userId)->first();
        }

        $approver = DB::connection('pgsql')->table('users')->where('email', 'p73_approver@maarif.test')->first();
        if (! $approver) {
            $approverId = DB::connection('pgsql')->table('users')->insertGetId([
                'organization_id' => $org->id,
                'name' => 'P73 Approver User',
                'email' => 'p73_approver@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $approver = DB::connection('pgsql')->table('users')->where('id', $approverId)->first();
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

        $ruleId = DB::connection('pgsql')->table('classification_rules')->insertGetId([
            'organization_id' => $org->id,
            'name' => 'P73 PostgreSQL Test Rule',
            'priority' => 1,
            'is_active' => true,
            'rule_type' => 'KEYWORD',
            'pattern' => 'BUNGA',
            'transaction_type' => 'BANK_INTEREST',
            'auto_post' => true,
            'max_auto_post_amount' => '1000000.00',
            'version' => 1,
            'created_by' => $user->id,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entryNumber = 'JE-P73-'.uniqid();
        $jeId = DB::connection('pgsql')->table('journal_entries')->insertGetId([
            'organization_id' => $org->id,
            'fiscal_period_id' => $periodId,
            'entry_number' => $entryNumber,
            'entry_date' => '2026-03-15',
            'entry_type' => 'receipt',
            'description' => 'P73 PostgreSQL Journal Test',
            'status' => 'draft',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $txNumber = 'BT-P73-'.uniqid();
        $btId = DB::connection('pgsql')->table('business_transactions')->insertGetId([
            'organization_id' => $org->id,
            'transaction_number' => $txNumber,
            'fiscal_period_id' => $periodId,
            'transaction_date' => '2026-03-15',
            'transaction_type' => 'BANK_INTEREST',
            'direction' => 'IN',
            'amount' => '50000.00',
            'description' => 'P73 PostgreSQL BT Test',
            'status' => 'draft',
            'auto_post_eligible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'org' => $org,
            'period_id' => $periodId,
            'user_id' => $user->id,
            'approver_id' => $approver->id,
            'rule_id' => $ruleId,
            'je_id' => $jeId,
            'bt_id' => $btId,
        ];
    }

    public function test_postgresql_posted_by_rule_id_restrict_on_delete(): void
    {
        $this->skipIfNotPostgreSql();
        $f = $this->getOrCreateFixtures();

        // Point the journal to the rule
        DB::connection('pgsql')->table('journal_entries')
            ->where('id', $f['je_id'])
            ->update([
                'posted_by_rule_id' => $f['rule_id'],
                'status' => 'posted',
                'posted_at' => now(),
            ]);

        // Attempting to delete the classification rule must fail with foreign key violation (RESTRICT)
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/journal_entries_posted_by_rule_id_foreign/i');

        try {
            DB::connection('pgsql')->table('classification_rules')->where('id', $f['rule_id'])->delete();
        } finally {
            // Clean up
            DB::connection('pgsql')->table('journal_entries')->where('id', $f['je_id'])->update(['posted_by_rule_id' => null, 'status' => 'draft']);
            DB::connection('pgsql')->table('journal_entries')->where('id', $f['je_id'])->delete();
            DB::connection('pgsql')->table('business_transactions')->where('id', $f['bt_id'])->delete();
            DB::connection('pgsql')->table('classification_rules')->where('id', $f['rule_id'])->delete();
        }
    }

