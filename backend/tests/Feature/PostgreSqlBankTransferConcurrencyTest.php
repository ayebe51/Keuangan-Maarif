<?php

namespace Tests\Feature;

use App\Domain\Accounting\Enums\TransferPairingStatus;
use App\Domain\Accounting\Exceptions\InvalidTransferPairingException;
use App\Domain\Accounting\Exceptions\UnpairedTransferException;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\AccountMappingResolver;
use App\Domain\Accounting\Services\BankTransferPostingCoordinator;
use App\Domain\Accounting\Services\BusinessTransactionPostingCoordinator;
use App\Domain\Accounting\Services\UnappliedCashHandler;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PostgreSqlBankTransferConcurrencyTest extends TestCase
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
     *     operator: User,
     *     approver: User,
     *     acc_bri_id: int,
     *     acc_bsi_id: int,
     *     acc_unapplied_id: int,
     *     bank_bri_id: int,
     *     bank_bsi_id: int
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

        // Operator User
        $operator = User::withoutGlobalScopes()->where('email', 'p76_operator@maarif.test')->first();
        if (! $operator) {
            $operator = User::create([
                'organization_id' => $org->id,
                'name' => 'P76 Operator User',
                'email' => 'p76_operator@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
            ]);
            $operator->syncPermissions(['journal.view', 'journal.create']);
        }

        // Approver / Checker User (Separate identity for SoD compliance)
        $approver = User::withoutGlobalScopes()->where('email', 'p76_approver@maarif.test')->first();
        if (! $approver) {
            $approver = User::create([
                'organization_id' => $org->id,
                'name' => 'P76 Approver User',
                'email' => 'p76_approver@maarif.test',
                'password' => bcrypt('secret'),
                'is_active' => true,
            ]);
            $approver->syncPermissions(['journal.view', 'journal.review', 'journal.approve', 'journal.post', 'journal.reverse']);
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

        // Ensure Titipan Kas / Unapplied Cash (2200) account exists
        $accUnapplied = DB::connection('pgsql')->table('accounts')->where('organization_id', $org->id)->where('code', '2200')->first();
        if (! $accUnapplied) {
            $accUnappliedId = DB::connection('pgsql')->table('accounts')->insertGetId([
                'organization_id' => $org->id,
                'code' => '2200',
                'name' => 'Titipan Kas / Unapplied Cash',
                'account_type' => 'liability',
                'normal_balance' => 'credit',
                'is_postable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $accUnappliedId = $accUnapplied->id;
        }

        // Bank Accounts: BRI (id 1 or matching account_number) and BSI (id 4 or matching account_number)
        $bankBri = DB::connection('pgsql')->table('bank_accounts')->where('organization_id', $org->id)->where('account_number', '0106-01-000308-30-8')->first();
        if (! $bankBri) {
            $bankBriId = DB::connection('pgsql')->table('bank_accounts')->insertGetId([
                'organization_id' => $org->id,
                'bank_name' => 'Bank BRI',
                'account_number' => '0106-01-000308-30-8',
                'account_name' => 'LP MAARIF NU CILACAP (GIRO 308)',
                'branch' => 'Cilacap',
                'type' => 'giro',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $bankBriId = $bankBri->id;
        }

        $bankBsi = DB::connection('pgsql')->table('bank_accounts')->where('organization_id', $org->id)->where('account_number', '7100-02-000405-10-1')->first();
        if (! $bankBsi) {
            $accBsi = DB::connection('pgsql')->table('accounts')->where('organization_id', $org->id)->where('code', '1111')->first();
            $accBsiId = $accBsi ? $accBsi->id : DB::connection('pgsql')->table('accounts')->insertGetId([
                'organization_id' => $org->id,
                'code' => '1111',
                'name' => 'Kas di Bank BSI',
                'account_type' => 'asset',
                'normal_balance' => 'debit',
                'is_postable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $bankBsiId = DB::connection('pgsql')->table('bank_accounts')->insertGetId([
                'organization_id' => $org->id,
                'bank_name' => 'Bank BSI',
                'account_number' => '7100-02-000405-10-1',
                'account_name' => 'LP MAARIF NU CILACAP BSI',
                'branch' => 'Cilacap Kota',
                'type' => 'giro',
                'account_id' => $accBsiId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $bankBsiId = $bankBsi->id;
        }

        // Dynamically resolve COA accounts via canonical resolver
        $resolver = app(AccountMappingResolver::class);
        $resolvedBri = $resolver->resolveBankAccount($bankBriId, $org->id);
        $resolvedBsi = $resolver->resolveBankAccount($bankBsiId, $org->id);

        return [
            'org' => $org,
            'period_id' => $periodId,
            'operator' => $operator,
            'approver' => $approver,
            'acc_bri_id' => $resolvedBri->id,
            'acc_bsi_id' => $resolvedBsi->id,
            'acc_unapplied_id' => $accUnappliedId,
            'bank_bri_id' => $bankBriId,
            'bank_bsi_id' => $bankBsiId,
        ];
    }

    /**
     * Helper to create a BankTransaction + BusinessTransaction on PostgreSQL.
     */
    private function createPostgresTransferTx(
        array $f,
        int $bankAccountId,
        string $direction,
        float $amount,
        string $date = '2026-03-12',
        string $desc = 'Transfer Antar Rekening'
    ): int {
        $unique = uniqid('pg_bt_');

        $bankTxId = DB::connection('pgsql')->table('bank_transactions')->insertGetId([
            'organization_id' => $f['org']->id,
            'bank_account_id' => $bankAccountId,
            'transaction_date' => $date,
            'value_date' => $date,
            'direction' => $direction,
            'amount' => $amount,
            'status' => 'classified',
            'description' => $desc,
            'fingerprint' => hash('sha256', "pg_{$unique}_{$direction}_{$amount}"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::connection('pgsql')->table('business_transactions')->insertGetId([
            'organization_id' => $f['org']->id,
            'bank_transaction_id' => $bankTxId,
            'fiscal_period_id' => $f['period_id'],
            'transaction_number' => "BT-{$unique}",
            'transaction_date' => $date,
            'value_date' => $date,
            'transaction_type' => 'BANK_TRANSFER',
            'direction' => $direction,
            'amount' => $amount,
            'status' => 'draft',
            'classification_status' => 'classified',
            'classification_method' => 'SYSTEM_TRANSFER_PAIRING',
            'transfer_pairing_status' => 'PENDING_PAIRING',
            'description' => $desc,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Helper to create an Unapplied Cash transaction on PostgreSQL.
     */
    private function createPostgresUnappliedCashTx(
        array $f,
        int $bankAccountId,
        float $amount,
        string $date = '2026-03-16',
        string $desc = 'Setoran Belum Teridentifikasi'
    ): int {
        $unique = uniqid('pg_uncash_');

        $bankTxId = DB::connection('pgsql')->table('bank_transactions')->insertGetId([
            'organization_id' => $f['org']->id,
            'bank_account_id' => $bankAccountId,
            'transaction_date' => $date,
            'value_date' => $date,
            'direction' => 'IN',
            'amount' => $amount,
            'status' => 'classified',
            'description' => $desc,
            'fingerprint' => hash('sha256', "pg_{$unique}_in_{$amount}"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::connection('pgsql')->table('business_transactions')->insertGetId([
            'organization_id' => $f['org']->id,
            'bank_transaction_id' => $bankTxId,
            'fiscal_period_id' => $f['period_id'],
            'transaction_number' => "BT-UNCASH-{$unique}",
            'transaction_date' => $date,
            'value_date' => $date,
            'transaction_type' => 'UNAPPLIED_CASH',
            'direction' => 'IN',
            'amount' => $amount,
            'target_account_id' => $f['acc_unapplied_id'],
            'status' => 'draft',
            'classification_status' => 'classified',
            'description' => $desc,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_postgresql_connection_and_version(): void
    {
        $this->skipIfNotPostgreSql();

        $version = DB::connection('pgsql')->select('SELECT version()')[0]->version ?? '';
        $this->assertStringContainsString('PostgreSQL', $version);
    }

    public function test_postgresql_2_to_1_transfer_pairing_and_balanced_draft_generation(): void
    {
        $this->skipIfNotPostgreSql();
        $f = $this->getOrCreateFixtures();
        TenantContext::setTenantId($f['org']->id);

        try {
            $amount = 3500000.00;

            // Leg OUT: BRI -> BSI
            $btOutId = $this->createPostgresTransferTx(
                $f,
                $f['bank_bri_id'],
                'OUT',
                $amount,
                '2026-03-12',
                'TRSF KE REK BSI TIGA JUTA LIMA RATUS'
