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
            );

            // Leg IN: BSI <- BRI (next day)
            $btInId = $this->createPostgresTransferTx(
                $f,
                $f['bank_bsi_id'],
                'IN',
                $amount,
                '2026-03-13',
                'TRSF DARI REK BRI TIGA JUTA LIMA RATUS'
            );

            $coordinator = app(BankTransferPostingCoordinator::class);

            // Pair the transactions
            $pairResult = $coordinator->pair($btOutId, $btInId);
            $this->assertTrue($pairResult->isSuccessful());
            $this->assertTrue($pairResult->isPaired());
            $this->assertSame(TransferPairingStatus::PAIRED, $pairResult->status);

            // Verify PostgreSQL row states
            $legOut = DB::connection('pgsql')->table('business_transactions')->where('id', $btOutId)->first();
            $legIn = DB::connection('pgsql')->table('business_transactions')->where('id', $btInId)->first();

            $this->assertSame('PAIRED', $legOut->transfer_pairing_status);
            $this->assertSame('PAIRED', $legIn->transfer_pairing_status);
            $this->assertSame($btInId, (int) $legOut->paired_business_transaction_id);
            $this->assertSame($btOutId, (int) $legIn->paired_business_transaction_id);

            // Generate Compound 2:1 Transfer Journal Draft
            $draftJournal = $coordinator->createTransferJournalDraft($btOutId, $f['operator']);

            $this->assertNotNull($draftJournal);
            $this->assertSame('draft', $draftJournal->status);
            $this->assertSame(JournalEntry::TYPE_ADJUSTMENT, $draftJournal->entry_type);

            // Database lines check on PostgreSQL
            $lines = DB::connection('pgsql')->table('journal_lines')
                ->where('journal_entry_id', $draftJournal->id)
                ->orderBy('line_number')
                ->get();

            $this->assertCount(2, $lines);
            // Line 1: Debit Destination Bank (BSI)
            $this->assertSame($f['acc_bsi_id'], (int) $lines[0]->account_id);
            $this->assertEquals(3500000.00, (float) $lines[0]->debit);
            $this->assertEquals(0.00, (float) $lines[0]->credit);

            // Line 2: Credit Source Bank (BRI)
            $this->assertSame($f['acc_bri_id'], (int) $lines[1]->account_id);
            $this->assertEquals(0.00, (float) $lines[1]->debit);
            $this->assertEquals(3500000.00, (float) $lines[1]->credit);

            // Verify journal_entry_sources has EXACTLY 2 rows on PostgreSQL
            $sources = DB::connection('pgsql')->table('journal_entry_sources')
                ->where('journal_entry_id', $draftJournal->id)
                ->orderBy('leg_role')
                ->get();

            $this->assertCount(2, $sources);
            $sourceRoles = $sources->pluck('leg_role')->all();
            sort($sourceRoles);
            $this->assertSame(['transfer_destination', 'transfer_source'], $sourceRoles);

            $sourceLegOut = $sources->firstWhere('leg_role', 'transfer_source');
            $this->assertSame($btOutId, (int) $sourceLegOut->business_transaction_id);

            $sourceLegIn = $sources->firstWhere('leg_role', 'transfer_destination');
            $this->assertSame($btInId, (int) $sourceLegIn->business_transaction_id);
        } finally {
            TenantContext::setTenantId(null);
        }
    }

    public function test_postgresql_concurrency_race_condition_guard_on_pairing(): void
    {
        $this->skipIfNotPostgreSql();
        $f = $this->getOrCreateFixtures();
        TenantContext::setTenantId($f['org']->id);

        try {
            $amount = 1250000.00;

            $btOutId = $this->createPostgresTransferTx(
                $f,
                $f['bank_bri_id'],
                'OUT',
                $amount,
                '2026-03-14',
                'TRSF OUT RACE TEST'
            );

            $btInId = $this->createPostgresTransferTx(
                $f,
                $f['bank_bsi_id'],
                'IN',
                $amount,
                '2026-03-14',
                'TRSF IN RACE TEST'
            );

            $coordinator = app(BankTransferPostingCoordinator::class);

            // First worker pairs successfully
            $res1 = $coordinator->pair($btOutId, $btInId);
            $this->assertTrue($res1->isSuccessful());

            // Second worker attempts to pair already paired transaction
            $this->expectException(InvalidTransferPairingException::class);
            $coordinator->pair($btOutId, $btInId);
        } finally {
            TenantContext::setTenantId(null);
        }
    }

    public function test_postgresql_twin_leg_synchronization_on_posting_and_reversal(): void
    {
        $this->skipIfNotPostgreSql();
        $f = $this->getOrCreateFixtures();
        TenantContext::setTenantId($f['org']->id);

        try {
            $amount = 2000000.00;

            $btOutId = $this->createPostgresTransferTx(
                $f,
                $f['bank_bri_id'],
                'OUT',
                $amount,
                '2026-03-10',
                'TRSF KE BSI SYNC TEST'
            );

            $btInId = $this->createPostgresTransferTx(
                $f,
                $f['bank_bsi_id'],
                'IN',
                $amount,
                '2026-03-10',
                'TRSF DARI BRI SYNC TEST'
            );

            $coordinator = app(BankTransferPostingCoordinator::class);
            $coordinator->pair($btOutId, $btInId);

            $draft = $coordinator->createTransferJournalDraft($btOutId, $f['operator']);

            // Human Posting via BusinessTransactionPostingCoordinator (Checker != Maker)
            $postingCoordinator = app(BusinessTransactionPostingCoordinator::class);
            $postResult = $postingCoordinator->approveAndPost($btOutId, $f['approver']);

            $this->assertTrue($postResult['success']);
            $postedEntry = $postResult['journal_entry'];
            $this->assertSame('posted', $postedEntry->status);

            // Assert BOTH legs are posted on PostgreSQL
            $legOut = DB::connection('pgsql')->table('business_transactions')->where('id', $btOutId)->first();
            $legIn = DB::connection('pgsql')->table('business_transactions')->where('id', $btInId)->first();

            $this->assertSame('posted', $legOut->status);
            $this->assertSame($postedEntry->id, (int) $legOut->posted_journal_entry_id);

            $this->assertSame('posted', $legIn->status);
            $this->assertSame($postedEntry->id, (int) $legIn->posted_journal_entry_id);

            // Reverse the posted transfer journal entry
            $revResult = $postingCoordinator->reverse($btOutId, $f['approver'], 'Mistaken internal transfer booking');
            $this->assertTrue($revResult['success']);

            // Assert BOTH legs are reverted to 'reversed' on PostgreSQL
            $legOutRev = DB::connection('pgsql')->table('business_transactions')->where('id', $btOutId)->first();
            $legInRev = DB::connection('pgsql')->table('business_transactions')->where('id', $btInId)->first();

            $this->assertSame('reversed', $legOutRev->status);
            $this->assertSame('reversed', $legInRev->status);
        } finally {
            TenantContext::setTenantId(null);
        }
    }

    public function test_postgresql_transfer_posting_guard_rejects_unpaired(): void
    {
        $this->skipIfNotPostgreSql();
        $f = $this->getOrCreateFixtures();
        TenantContext::setTenantId($f['org']->id);

        try {
            $amount = 800000.00;

            $btOutId = $this->createPostgresTransferTx(
                $f,
                $f['bank_bri_id'],
                'OUT',
                $amount,
                '2026-03-11',
                'UNPAIRED TRANSFER GUARD TEST'
            );

            // Attempt to post unpaired transfer transaction
            $postingCoordinator = app(BusinessTransactionPostingCoordinator::class);
            $this->expectException(UnpairedTransferException::class);
            $postingCoordinator->approveAndPost($btOutId, $f['approver']);
        } finally {
            TenantContext::setTenantId(null);
        }
    }

    public function test_postgresql_unapplied_cash_draft_creation_strictly_pnl_neutral(): void
    {
        $this->skipIfNotPostgreSql();
        $f = $this->getOrCreateFixtures();
        TenantContext::setTenantId($f['org']->id);

        try {
            $amount = 1750000.00;

            // Incoming transaction with UNAPPLIED_CASH mapping to liability account 2200
            $btId = $this->createPostgresUnappliedCashTx(
                $f,
                $f['bank_bri_id'],
                $amount,
                '2026-03-16',
                'TRANSFER DARI DONATUR GELAP UNAPPLIED'
            );

            $handler = app(UnappliedCashHandler::class);
            $bt = BusinessTransaction::withoutGlobalScopes()->findOrFail($btId);

            $this->assertTrue($handler->identify($bt));

            $draft = $handler->createDraft($bt, $f['operator']);

            $this->assertNotNull($draft);
            $this->assertSame('draft', $draft->status);
            $this->assertSame('receipt', $draft->entry_type);

            // Verify lines in PostgreSQL
            $lines = DB::connection('pgsql')->table('journal_lines')
                ->where('journal_entry_id', $draft->id)
                ->orderBy('line_number')
                ->get();

            $this->assertCount(2, $lines);

            // Line 1: Debit Bank Asset
            $this->assertSame($f['acc_bri_id'], (int) $lines[0]->account_id);
            $this->assertEquals(1750000.00, (float) $lines[0]->debit);
            $this->assertEquals(0.00, (float) $lines[0]->credit);

            // Line 2: Credit Liability Account 2200 (Titipan Kas) - ZERO revenue recognition!
            $this->assertSame($f['acc_unapplied_id'], (int) $lines[1]->account_id);
            $this->assertEquals(0.00, (float) $lines[1]->debit);
            $this->assertEquals(1750000.00, (float) $lines[1]->credit);

            // Verify account type of account 2200 is liability
            $accType = DB::connection('pgsql')->table('accounts')->where('id', $lines[1]->account_id)->value('account_type');
            $this->assertSame('liability', $accType);

            // Verify provenance in journal_entry_sources
            $source = DB::connection('pgsql')->table('journal_entry_sources')
                ->where('journal_entry_id', $draft->id)
                ->first();

            $this->assertNotNull($source);
            $this->assertSame('primary', $source->leg_role);
            $this->assertSame('business_transaction', $source->source_type);
            $this->assertSame($btId, (int) $source->business_transaction_id);
        } finally {
            TenantContext::setTenantId(null);
        }
    }

    public function test_postgresql_rollback_on_invalid_pairing_preserves_state(): void
    {
        $this->skipIfNotPostgreSql();
        $f = $this->getOrCreateFixtures();
        TenantContext::setTenantId($f['org']->id);

        try {
            // Two transactions with different amounts (incompatible)
            $btOutId = $this->createPostgresTransferTx(
                $f,
                $f['bank_bri_id'],
                'OUT',
                1000000.00,
                '2026-03-12',
                'TRSF OUT RB TEST'
            );

            $btInId = $this->createPostgresTransferTx(
                $f,
                $f['bank_bsi_id'],
                'IN',
                2000000.00, // Incompatible amount!
                '2026-03-12',
                'TRSF IN RB TEST'
            );

            $coordinator = app(BankTransferPostingCoordinator::class);

            try {
                $coordinator->pair($btOutId, $btInId);
                $this->fail('Expected InvalidTransferPairingException was not thrown.');
            } catch (InvalidTransferPairingException $e) {
                $this->assertStringContainsString('do not match', $e->getMessage());
            }

            // Verify both rows remained unchanged
            $legOut = DB::connection('pgsql')->table('business_transactions')->where('id', $btOutId)->first();
            $legIn = DB::connection('pgsql')->table('business_transactions')->where('id', $btInId)->first();

            $this->assertSame('PENDING_PAIRING', $legOut->transfer_pairing_status);
            $this->assertNull($legOut->paired_business_transaction_id);

            $this->assertSame('PENDING_PAIRING', $legIn->transfer_pairing_status);
            $this->assertNull($legIn->paired_business_transaction_id);
        } finally {
            TenantContext::setTenantId(null);
        }
    }
}
