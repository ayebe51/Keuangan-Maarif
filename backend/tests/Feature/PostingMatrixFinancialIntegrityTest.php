<?php

namespace Tests\Feature;

use App\Domain\Accounting\Enums\PostingCategory;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Services\AccountMappingResolver;
use App\Domain\Accounting\Services\PostingMatrixResolver;
use App\Domain\Accounting\ValueObjects\Money;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Fund\Models\Fund;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use Database\Seeders\CoaSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostingMatrixFinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private BankAccount $bank308;

    private BankAccount $bank304;

    private Fund $fundAbadi;

    private AccountMappingResolver $accountResolver;

    private PostingMatrixResolver $matrixResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(OrganizationSeeder::class);
        $this->seed(CoaSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $this->orgA = Organization::where('code', 'LP-MAARIF-CLP')->firstOrFail();
        $this->orgB = Organization::create([
            'code' => 'ORG-TEST-B',
            'name' => 'Organisasi Test B',
            'type' => 'madrasah',
            'is_active' => true,
        ]);

        TenantContext::setTenantId($this->orgA->id);

        $this->bank308 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%308%')->firstOrFail();
        $this->bank304 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%304%')->firstOrFail();
        $this->fundAbadi = Fund::where('organization_id', $this->orgA->id)
            ->where('code', 'DANA-ABADI')->firstOrFail();

        $this->accountResolver = app(AccountMappingResolver::class);
        $this->matrixResolver = app(PostingMatrixResolver::class);
    }

    public function test_no_journal_entries_or_lines_created_by_p71_resolvers(): void
    {
        $initialJournalsCount = JournalEntry::withoutGlobalScopes()->count();
        $initialLinesCount = JournalLine::withoutGlobalScopes()->count();

        // Perform various resolutions across all categories
        $this->matrixResolver->resolve(PostingCategory::RECEIVABLE_PAYMENT, 'IN');
        $this->matrixResolver->resolve(PostingCategory::DIRECT_INCOME, 'IN');
        $this->matrixResolver->resolve(PostingCategory::OPERATING_EXPENSE, 'OUT');

        $this->accountResolver->resolve(PostingCategory::DIRECT_INCOME, $this->orgA->id, [
            'bank_account' => $this->bank308,
        ]);
        $this->accountResolver->resolve(PostingCategory::OPERATING_EXPENSE, $this->orgA->id, [
            'bank_account' => $this->bank308,
        ]);
        $this->accountResolver->resolve(PostingCategory::BANK_TRANSFER, $this->orgA->id, [
            'source_bank_account' => $this->bank308,
            'destination_bank_account' => $this->bank304,
        ]);

        // INVARIANT: Zero journals created
        $this->assertSame($initialJournalsCount, JournalEntry::withoutGlobalScopes()->count());
        $this->assertSame($initialLinesCount, JournalLine::withoutGlobalScopes()->count());
    }

    public function test_no_database_mutations_originate_from_resolvers(): void
    {
        $accountsCountBefore = DB::table('accounts')->count();
        $mappingsCountBefore = DB::table('account_mappings')->count();
        $bankAccountsCountBefore = DB::table('bank_accounts')->count();
        $fundsCountBefore = DB::table('funds')->count();

        // Run full master resolve
        $this->accountResolver->resolve(PostingCategory::RECEIVABLE_PAYMENT, $this->orgA->id, [
            'bank_account' => $this->bank308,
            'is_overpayment' => true,
        ]);
        $this->accountResolver->resolve(PostingCategory::BANK_FEE_TAX, $this->orgA->id, [
            'bank_account' => $this->bank308,
            'subtype' => 'ADMIN_FEE',
        ]);
        $this->accountResolver->resolve(PostingCategory::ALLOCATION_DISBURSEMENT, $this->orgA->id, [
            'bank_account' => $this->bank308,
            'fund' => $this->fundAbadi,
        ]);

        // INVARIANT: Pure read-only resolution
        $this->assertSame($accountsCountBefore, DB::table('accounts')->count());
        $this->assertSame($mappingsCountBefore, DB::table('account_mappings')->count());
        $this->assertSame($bankAccountsCountBefore, DB::table('bank_accounts')->count());
        $this->assertSame($fundsCountBefore, DB::table('funds')->count());
    }

    public function test_resolvers_never_produce_cross_tenant_accounts(): void
    {
        $resolved = $this->accountResolver->resolve(PostingCategory::DIRECT_INCOME, $this->orgA->id, [
            'bank_account' => $this->bank308,
        ]);

        $this->assertSame($this->orgA->id, (int) $resolved->getDebitAccount()->organization_id);
        $this->assertSame($this->orgA->id, (int) $resolved->getCreditAccount()->organization_id);
        $this->assertNotSame($this->orgB->id, (int) $resolved->getDebitAccount()->organization_id);
        $this->assertNotSame($this->orgB->id, (int) $resolved->getCreditAccount()->organization_id);
    }

    public function test_resolvers_reject_cross_tenant_injection_attempts(): void
    {
        // Create an account in Org B
        $accountB = TenantContext::forTenant($this->orgB->id, function () {
            return Account::create([
                'organization_id' => $this->orgB->id,
                'code' => '4999',
                'name' => 'Pendapatan Gelap Org B',
                'account_type' => Account::TYPE_REVENUE,
                'normal_balance' => Account::BALANCE_CREDIT,
                'is_postable' => true,
                'is_active' => true,
            ]);
        });

        $this->expectException(CrossTenantViolationException::class);

        // Attempt to resolve revenue in Org A context with target account from Org B
        $this->accountResolver->resolve(PostingCategory::DIRECT_INCOME, $this->orgA->id, [
            'bank_account' => $this->bank308,
            'target_account_id' => $accountB->id,
        ]);
    }

    public function test_money_value_object_exactness_without_floats(): void
    {
        $m1 = Money::of('852000.00');
        $m2 = Money::of('148000.00');
        $total = $m1->add($m2);

        $this->assertSame('1000000.00', $total->getAmount());
        $this->assertTrue($total->equals(Money::of('1000000.00')));
    }
}
