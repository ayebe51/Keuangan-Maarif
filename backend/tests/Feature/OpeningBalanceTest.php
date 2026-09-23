<?php

namespace Tests\Feature;

use App\Domain\Accounting\Exceptions\AlreadyPostedException;
use App\Domain\Accounting\Exceptions\ImmutableJournalException;
use App\Domain\Accounting\Exceptions\OpeningBalanceConfigurationException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Accounting\Models\OpeningBalanceSource;
use App\Domain\Accounting\Services\OpeningBalanceService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $maker;
    private User $checker;
    private OpeningBalanceService $obService;
    private int $bankAccountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\CoaSeeder::class);

        $this->org = Organization::where('code', 'LP-MAARIF-CLP')->first();
        TenantContext::setTenantId($this->org->id);

        $this->maker = User::where('email', 'operator@maarif-cilacap.org')->first();
        $this->checker = User::where('email', 'admin@maarif-cilacap.org')->first();

        $this->obService = app(OpeningBalanceService::class);

        // Get seeded bank account
        $bank = DB::table('bank_accounts')->where('organization_id', $this->org->id)->first();
        $this->bankAccountId = $bank->id;
    }

    public function test_bank_account_table_has_no_opening_balance_column(): void
    {
        // Guardrail verification: BankAccount table MUST NOT have opening_balance column
        $this->assertFalse(
            Schema::hasColumn('bank_accounts', 'opening_balance'),
            'Architectural violation: bank_accounts table must not have opening_balance column.'
        );
    }

    public function test_opening_balance_debit_direction_for_bank_account(): void
    {
        // AT-016: Debit Bank, Credit Equity Saldo Awal
        $source = $this->obService->recordSource([
            'organization_id' => $this->org->id,
            'bank_account_id' => $this->bankAccountId,
            'effective_date' => '2026-03-01',
            'amount' => '72125396.20', // Golden Dataset BRI Giro 308
            'direction' => 'debit',
            'description' => 'Saldo Awal BRI Giro 308 per 01 Maret 2026',
        ], $this->maker);

        $this->assertFalse($source->isPosted());
        $this->assertSame('72125396.20', $source->amount);

        // Process to journal
        $journal = $this->obService->processToJournal($source, $this->maker, $this->checker);

        $this->assertTrue($journal->isPosted());
        $this->assertSame('opening_balance', $journal->entry_type);

        $lines = $journal->lines()->orderBy('line_number')->get();
        $this->assertCount(2, $lines);

        // Line 1: Bank Account Debit
        $this->assertSame('72125396.20', $lines[0]->debit);
        $this->assertSame('0.00', $lines[0]->credit);
        $this->assertSame($this->bankAccountId, $lines[0]->bank_account_id);

        // Line 2: Equity Counterpart Credit
        $this->assertSame('0.00', $lines[1]->debit);
        $this->assertSame('72125396.20', $lines[1]->credit);

        // Verify source updated and linked
        $source->refresh();
        $this->assertTrue($source->isPosted());
        $this->assertSame($journal->id, $source->journal_entry_id);
    }

    public function test_opening_balance_credit_direction_for_liability(): void
    {
        // AT-017: Opening balance credit direction (e.g. initial liability / unapplied)
        $liabilityAccount = Account::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', '2200')->first();

        $source = $this->obService->recordSource([
            'organization_id' => $this->org->id,
            'effective_date' => '2026-03-01',
            'amount' => '5000000.00',
            'direction' => 'credit',
            'description' => 'Saldo Awal Titipan Unapplied',
        ], $this->maker);

        $journal = $this->obService->processToJournal(
            source: $source,
            maker: $this->maker,
            checker: $this->checker,
            explicitAccountId: $liabilityAccount->id
        );

        $this->assertTrue($journal->isPosted());

        $lines = $journal->lines()->orderBy('line_number')->get();
        // Line 1: Equity Debit
        $this->assertSame('5000000.00', $lines[0]->debit);
        $this->assertSame('0.00', $lines[0]->credit);

        // Line 2: Liability Credit
        $this->assertSame('0.00', $lines[1]->debit);
        $this->assertSame('5000000.00', $lines[1]->credit);
        $this->assertSame($liabilityAccount->id, $lines[1]->account_id);
    }

    public function test_opening_balance_idempotency_prevents_double_posting(): void
    {
        // AT-018: Idempotency
        $source = $this->obService->recordSource([
            'organization_id' => $this->org->id,
            'bank_account_id' => $this->bankAccountId,
            'effective_date' => '2026-03-01',
            'amount' => '1000000.00',
            'direction' => 'debit',
        ], $this->maker);

        $this->obService->processToJournal($source, $this->maker, $this->checker);

        // Second call must fail
        $this->expectException(AlreadyPostedException::class);
        $this->obService->processToJournal($source, $this->maker, $this->checker);
    }

    public function test_missing_bank_mapping_throws_configuration_exception(): void
    {
        // AT-015: Bank source without mapping throws OpeningBalanceConfigurationException
        $newBankId = DB::table('bank_accounts')->insertGetId([
            'organization_id' => $this->org->id,
            'bank_name' => 'Bank Mandiri',
            'account_number' => '9999-00-1111-22-3',
            'account_name' => 'LP MAARIF MANDIRI UNMAPPED',
            'type' => 'current',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $source = $this->obService->recordSource([
            'organization_id' => $this->org->id,
            'bank_account_id' => $newBankId,
            'effective_date' => '2026-03-01',
            'amount' => '1000000.00',
            'direction' => 'debit',
        ], $this->maker);

        $this->expectException(OpeningBalanceConfigurationException::class);
        $this->obService->processToJournal($source, $this->maker, $this->checker);
    }

    public function test_posted_opening_balance_source_is_immutable(): void
    {
        // AT-008 applied to OBS: modifying or deleting posted OBS throws ImmutableJournalException
        $source = $this->obService->recordSource([
            'organization_id' => $this->org->id,
            'bank_account_id' => $this->bankAccountId,
            'effective_date' => '2026-03-01',
            'amount' => '1000000.00',
            'direction' => 'debit',
        ], $this->maker);

        $this->obService->processToJournal($source, $this->maker, $this->checker);

        // Attempt modify
        try {
            $source->amount = '2000000.00';
            $source->save();
            $this->fail('Expected ImmutableJournalException on modifying posted opening balance source');
        } catch (ImmutableJournalException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Attempt delete
        try {
            $source->delete();
            $this->fail('Expected ImmutableJournalException on deleting posted opening balance source');
        } catch (ImmutableJournalException $e) {
            $this->assertStringContainsString('Cannot delete', $e->getMessage());
        }
    }
}
