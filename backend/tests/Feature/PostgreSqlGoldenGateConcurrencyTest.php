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
