<?php

namespace Tests\Feature;

use App\Domain\Accounting\Enums\PostingCategory;
use App\Domain\Accounting\Exceptions\AmbiguousAccountMappingException;
use App\Domain\Accounting\Exceptions\InactiveAccountException;
use App\Domain\Accounting\Exceptions\InvalidAccountTypeException;
use App\Domain\Accounting\Exceptions\InvalidFundAccountMappingException;
use App\Domain\Accounting\Exceptions\InvalidNormalBalanceException;
use App\Domain\Accounting\Exceptions\NonPostableAccountException;
use App\Domain\Accounting\Exceptions\UnresolvedAccountMappingException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Accounting\Services\AccountMappingResolver;
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
use Tests\TestCase;

class AccountMappingResolverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private BankAccount $bankGiro308;

    private BankAccount $bankGiro304;

    private BankAccount $bankTab538;

    private AccountMappingResolver $resolver;

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

        $this->bankGiro308 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%308%')->firstOrFail();
        $this->bankGiro304 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%304%')->firstOrFail();
        $this->bankTab538 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%538%')->firstOrFail();

        $this->resolver = app(AccountMappingResolver::class);
    }

    public function test_direct_bank_account_resolution_succeeds(): void
    {
        $account = $this->resolver->resolveBankAccount($this->bankGiro308, $this->orgA->id);

        $this->assertInstanceOf(Account::class, $account);
        $this->assertSame('1120', $account->code);
        $this->assertSame(Account::TYPE_ASSET, $account->account_type);
        $this->assertSame(Account::BALANCE_DEBIT, $account->normal_balance);
        $this->assertTrue($account->is_postable);
        $this->assertTrue($account->is_active);
    }

    public function test_fallback_type_bank_account_mapping_succeeds_when_account_id_null(): void
    {
        $newBank = BankAccount::create([
            'organization_id' => $this->orgA->id,
            'bank_name' => 'Bank Mandiri',
            'account_number' => '139-00-1234567-8',
            'account_name' => 'LP MAARIF MANDIRI',
            'type' => BankAccount::TYPE_CURRENT,
            'account_id' => null, // account_id is null
            'is_active' => true,
        ]);

        $coaKas = Account::where('organization_id', $this->orgA->id)->where('code', '1110')->firstOrFail();

        AccountMapping::create([
            'organization_id' => $this->orgA->id,
            'account_id' => $coaKas->id,
            'mapping_type' => AccountMapping::TYPE_BANK_ACCOUNT,
            'mapping_key' => (string) $newBank->id,
            'is_active' => true,
        ]);

        $resolved = $this->resolver->resolveBankAccount($newBank, $this->orgA->id);
        $this->assertSame($coaKas->id, $resolved->id);
    }

    public function test_conflicting_bank_account_mapping_rejected(): void
    {
        $coaKas = Account::where('organization_id', $this->orgA->id)->where('code', '1110')->firstOrFail();
        $coa308 = Account::where('organization_id', $this->orgA->id)->where('code', '1120')->firstOrFail();

        // Direct is 1120, but mapping points to 1110
        AccountMapping::where('organization_id', $this->orgA->id)
            ->where('mapping_type', AccountMapping::TYPE_BANK_ACCOUNT)
            ->where('mapping_key', (string) $this->bankGiro308->id)
            ->update(['account_id' => $coaKas->id]);

        $this->expectException(AmbiguousAccountMappingException::class);
        $this->resolver->resolveBankAccount($this->bankGiro308, $this->orgA->id);
    }

    public function test_missing_bank_mapping_rejected(): void
    {
        $bankUnmapped = BankAccount::create([
            'organization_id' => $this->orgA->id,
            'bank_name' => 'Bank BNI',
            'account_number' => '099-887766-5',
            'account_name' => 'LP MAARIF BNI',
            'type' => BankAccount::TYPE_CURRENT,
            'account_id' => null,
            'is_active' => true,
        ]);

        $this->expectException(UnresolvedAccountMappingException::class);
        $this->resolver->resolveBankAccount($bankUnmapped, $this->orgA->id);
    }

    public function test_cross_tenant_bank_account_rejected(): void
    {
        $bankOrgB = TenantContext::forTenant($this->orgB->id, function () {
            return BankAccount::create([
                'organization_id' => $this->orgB->id,
                'bank_name' => 'Bank BJB Org B',
                'account_number' => '999-000-111',
                'account_name' => 'Org B Account',
                'type' => BankAccount::TYPE_SAVINGS,
                'is_active' => true,
            ]);
        });

        $this->expectException(CrossTenantViolationException::class);
        $this->resolver->resolveBankAccount($bankOrgB, $this->orgA->id);
    }

    public function test_inactive_account_rejected(): void
    {
        $inactiveAccount = Account::create([
            'organization_id' => $this->orgA->id,
            'code' => '1199',
            'name' => 'Rekening Non-Aktif',
            'account_type' => Account::TYPE_ASSET,
            'normal_balance' => Account::BALANCE_DEBIT,
            'is_postable' => true,
            'is_active' => false, // Inactive
        ]);

        $this->expectException(InactiveAccountException::class);
        $this->resolver->validateAccount($inactiveAccount, $this->orgA->id);
    }

    public function test_non_postable_account_rejected(): void
    {
        // 1100 Kas dan Setara Kas is a summary account (is_postable = false)
        $headerAccount = Account::where('organization_id', $this->orgA->id)->where('code', '1100')->firstOrFail();

        $this->expectException(NonPostableAccountException::class);
        $this->resolver->validateAccount($headerAccount, $this->orgA->id);
    }

    public function test_header_account_with_children_rejected(): void
    {
        // 1000 ASET has children
        $rootAsset = Account::where('organization_id', $this->orgA->id)->where('code', '1000')->firstOrFail();

        $this->expectException(NonPostableAccountException::class);
        $this->resolver->validateAccount($rootAsset, $this->orgA->id);
    }

    public function test_invalid_account_type_rejected(): void
    {
        // 5100 is EXPENSE, expect ASSET
        $expenseAccount = Account::where('organization_id', $this->orgA->id)->where('code', '5100')->firstOrFail();

        $this->expectException(InvalidAccountTypeException::class);
        $this->resolver->validateAccount($expenseAccount, $this->orgA->id, Account::TYPE_ASSET);
    }

    public function test_invalid_normal_balance_rejected(): void
    {
        // 1110 has normal_balance = debit, expect credit
        $kasAccount = Account::where('organization_id', $this->orgA->id)->where('code', '1110')->firstOrFail();

        $this->expectException(InvalidNormalBalanceException::class);
        $this->resolver->validateAccount($kasAccount, $this->orgA->id, null, Account::BALANCE_CREDIT);
    }

    public function test_receivable_account_resolution(): void
    {
        $account = $this->resolver->resolveReceivableAccount($this->orgA->id);

        $this->assertSame('1210', $account->code);
        $this->assertSame(Account::TYPE_ASSET, $account->account_type);
        $this->assertSame(Account::BALANCE_DEBIT, $account->normal_balance);
        $this->assertTrue($account->is_postable);
    }

    public function test_revenue_account_resolution(): void
    {
        $account = $this->resolver->resolveRevenueAccount($this->orgA->id);

        $this->assertSame('4100', $account->code);
        $this->assertSame(Account::TYPE_REVENUE, $account->account_type);
        $this->assertSame(Account::BALANCE_CREDIT, $account->normal_balance);
        $this->assertTrue($account->is_postable);
    }

    public function test_interest_revenue_account_resolution(): void
    {
        $account = $this->resolver->resolveInterestRevenueAccount($this->orgA->id);

        $this->assertSame('4200', $account->code);
        $this->assertSame(Account::TYPE_REVENUE, $account->account_type);
        $this->assertSame(Account::BALANCE_CREDIT, $account->normal_balance);
    }

    public function test_bank_fee_account_resolution(): void
    {
        $account = $this->resolver->resolveBankFeeAccount($this->orgA->id);

        $this->assertSame('5200', $account->code);
        $this->assertSame(Account::TYPE_EXPENSE, $account->account_type);
        $this->assertSame(Account::BALANCE_DEBIT, $account->normal_balance);
    }

    public function test_bank_tax_account_resolution(): void
    {
        $account = $this->resolver->resolveBankTaxAccount($this->orgA->id);

        $this->assertSame('5300', $account->code);
        $this->assertSame(Account::TYPE_EXPENSE, $account->account_type);
        $this->assertSame(Account::BALANCE_DEBIT, $account->normal_balance);
    }

    public function test_operating_expense_account_resolution(): void
    {
        $account = $this->resolver->resolveOperatingExpenseAccount($this->orgA->id);

        $this->assertSame('5100', $account->code);
        $this->assertSame(Account::TYPE_EXPENSE, $account->account_type);
        $this->assertSame(Account::BALANCE_DEBIT, $account->normal_balance);
    }

    public function test_fund_allocation_account_resolution(): void
    {
        $fund = Fund::where('organization_id', $this->orgA->id)->where('code', 'DANA-ABADI')->firstOrFail();

        $account = $this->resolver->resolveFundAllocationAccount($fund, $this->orgA->id);

        $this->assertInstanceOf(Account::class, $account);
        $this->assertSame(Account::TYPE_LIABILITY, $account->account_type);
        $this->assertSame(Account::BALANCE_CREDIT, $account->normal_balance);
    }

    public function test_inactive_fund_rejected(): void
    {
        $inactiveFund = Fund::create([
            'organization_id' => $this->orgA->id,
            'code' => 'DANA-INACTIVE',
            'name' => 'Dana Tidak Aktif',
            'fund_type' => Fund::TYPE_RESTRICTED,
            'is_active' => false,
            'status' => Fund::STATUS_CANCELLED,
        ]);

        $this->expectException(InvalidFundAccountMappingException::class);
        $this->resolver->resolveFundAllocationAccount($inactiveFund, $this->orgA->id);
    }

    public function test_unapplied_cash_account_resolution(): void
    {
        $account = $this->resolver->resolveUnappliedCashAccount($this->orgA->id);

        $this->assertSame('2200', $account->code);
        $this->assertSame(Account::TYPE_LIABILITY, $account->account_type);
        $this->assertSame(Account::BALANCE_CREDIT, $account->normal_balance);
    }

    public function test_master_resolve_orchestration_for_direct_income(): void
    {
        $resolved = $this->resolver->resolve(PostingCategory::DIRECT_INCOME, $this->orgA->id, [
            'bank_account' => $this->bankGiro308,
        ]);

        $this->assertSame('1120', $resolved->getDebitAccount()->code);
        $this->assertSame('4100', $resolved->getCreditAccount()->code);
    }

    public function test_master_resolve_orchestration_for_bank_transfer(): void
    {
        $resolved = $this->resolver->resolve(PostingCategory::BANK_TRANSFER, $this->orgA->id, [
            'source_bank_account' => $this->bankGiro308,
            'destination_bank_account' => $this->bankGiro304,
        ]);

        $this->assertSame('1130', $resolved->getDebitAccount()->code); // Destination debited
        $this->assertSame('1120', $resolved->getCreditAccount()->code); // Source credited
    }

    public function test_master_resolve_orchestration_for_bank_fee_tax(): void
    {
        $resolvedFee = $this->resolver->resolve(PostingCategory::BANK_FEE_TAX, $this->orgA->id, [
            'bank_account' => $this->bankGiro308,
            'subtype' => 'ADMIN_FEE',
        ]);
        $this->assertSame('5200', $resolvedFee->getDebitAccount()->code); // Beban Adm Bank
        $this->assertSame('1120', $resolvedFee->getCreditAccount()->code); // Bank credited

        $resolvedTax = $this->resolver->resolve(PostingCategory::BANK_FEE_TAX, $this->orgA->id, [
            'bank_account' => $this->bankGiro308,
            'subtype' => 'TAX',
        ]);
        $this->assertSame('5300', $resolvedTax->getDebitAccount()->code); // Beban Pajak Bunga Bank
        $this->assertSame('1120', $resolvedTax->getCreditAccount()->code); // Bank credited
    }
}
