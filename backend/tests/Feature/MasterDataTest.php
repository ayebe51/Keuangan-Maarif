<?php

namespace Tests\Feature;

use App\Domain\Accounting\Exceptions\OpeningBalanceConfigurationException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Services\BankAccountService;
use App\Domain\Classification\Models\TransactionCategory;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Counterparty\Models\CounterpartyAlias;
use App\Domain\Counterparty\Services\CounterpartyService;
use App\Domain\Fund\Exceptions\NegativeFundBalanceException;
use App\Domain\Fund\Models\Fund;
use App\Domain\Fund\Models\FundAllocation;
use App\Domain\Fund\Services\FundService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use App\Domain\Program\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;
    private Organization $orgB;
    private User $adminA;
    private User $adminB;
    private BankAccountService $bankAccountService;
    private CounterpartyService $counterpartyService;
    private FundService $fundService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\CoaSeeder::class);
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $this->orgA = Organization::where('code', 'LP-MAARIF-CLP')->firstOrFail();
        $this->orgB = Organization::create([
            'code' => 'ORG-TEST-B',
            'name' => 'Organisasi Test B',
            'type' => 'madrasah',
            'is_active' => true,
        ]);

        $this->adminA = User::where('email', 'admin@maarif-cilacap.org')->firstOrFail();

        $this->adminB = User::create([
            'organization_id' => $this->orgB->id,
            'name' => 'Admin Org B',
            'email' => 'admin-b@test.org',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
        $this->adminB->assignRole(\App\Domain\Organization\Models\Role::ACCOUNTING_ADMIN);

        $this->bankAccountService = app(BankAccountService::class);
        $this->counterpartyService = app(CounterpartyService::class);
        $this->fundService = app(FundService::class);
    }

    public function test_master_data_seeder_populates_golden_dataset(): void
    {
        TenantContext::setTenantId($this->orgA->id);

        // 1. Bank Accounts (3 BRI + 1 Kas Tunai)
        $accounts = BankAccount::where('organization_id', $this->orgA->id)->get();
        $this->assertCount(4, $accounts);

        $giro308 = $accounts->firstWhere('account_number', '0106-01-000308-30-8');
        $this->assertNotNull($giro308);
        $this->assertEquals(BankAccount::TYPE_GIRO, $giro308->type);
        $this->assertNotNull($giro308->account_id);

        // 2. System Counterparties
        $bankBri = Counterparty::where('organization_id', $this->orgA->id)
            ->where('code', 'BANK-BRI')
            ->first();
        $this->assertNotNull($bankBri);
        $this->assertEquals(Counterparty::ROLE_BANK, $bankBri->role);

