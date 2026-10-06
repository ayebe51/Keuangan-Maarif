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

        // Aliases attached
        $this->assertTrue(
            CounterpartyAlias::where('counterparty_id', $bankBri->id)
                ->where('alias_name', 'Bunga Rekening (Bank)')
                ->exists()
        );

        // 3. Golden School: MI Darwata Sindangbarang
        $darwata = Counterparty::where('organization_id', $this->orgA->id)
            ->where('code', 'MI-DARWATA-01')
            ->first();
        $this->assertNotNull($darwata);

        // 4. Categories & Funds
        $this->assertTrue(TransactionCategory::where('organization_id', $this->orgA->id)->where('code', 'IN-BOS')->exists());
        $this->assertTrue(Fund::where('organization_id', $this->orgA->id)->where('code', 'DANA-ABADI')->exists());
        $this->assertTrue(Program::where('organization_id', $this->orgA->id)->where('code', 'PROG-BOS')->exists());
    }

    public function test_bank_account_opening_balance_prohibited(): void
    {
        TenantContext::setTenantId($this->orgA->id);

        // Guard via Service
        $this->expectException(OpeningBalanceConfigurationException::class);
        $this->bankAccountService->create([
            'organization_id' => $this->orgA->id,
            'bank_name' => 'Bank Mandiri',
            'account_number' => '1234567890',
            'account_name' => 'Rekening Tes',
            'opening_balance' => 50000000,
        ]);
    }

    public function test_bank_account_opening_balance_prohibited_via_api(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->postJson('/api/v1/bank-accounts', [
            'bank_name' => 'Bank Mandiri',
            'account_number' => '1234567890',
            'account_name' => 'Rekening Tes',
            'opening_balance' => 50000000,
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['opening_balance']);
    }

    public function test_bank_account_tenant_isolation(): void
    {
        Sanctum::actingAs($this->adminA);

        // Create bank account in Org A
        $createRes = $this->postJson('/api/v1/bank-accounts', [
            'bank_name' => 'Bank Jateng',
            'account_number' => '9988776655',
            'account_name' => 'Kas Cabang Khusus',
            'type' => 'current',
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);
        $createRes->assertStatus(201);
        $accId = $createRes->json('data.id');

        // Admin B trying to access Org A's bank account
        Sanctum::actingAs($this->adminB);
        $getRes = $this->getJson("/api/v1/bank-accounts/{$accId}", [
            'X-Tenant-ID' => (string) $this->orgB->id,
        ]);
        $getRes->assertStatus(404);
    }

    public function test_counterparty_resolution_engine(): void
    {
        TenantContext::setTenantId($this->orgA->id);

        // 1. Exact Name Match
        $resolved1 = $this->counterpartyService->resolve($this->orgA->id, 'Bank BRI');
        $this->assertNotNull($resolved1);
        $this->assertEquals('BANK-BRI', $resolved1->code);

        // 2. Alias Match
        $resolved2 = $this->counterpartyService->resolve($this->orgA->id, 'Bunga Rekening (Bank)');
        $this->assertNotNull($resolved2);
        $this->assertEquals('BANK-BRI', $resolved2->code);

        // 3. Substring match inside mutation description: MI Darwata
        $resolved3 = $this->counterpartyService->resolve(
            $this->orgA->id,
            'SETORAN KAS MI DARWATA SINDANGBARANG TAHAP 1'
        );
        $this->assertNotNull($resolved3);
        $this->assertEquals('MI-DARWATA-01', $resolved3->code);

        // 4. Unknown string returns null
        $resolved4 = $this->counterpartyService->resolve($this->orgA->id, 'NAMA TIDAK DIKENAL XYZ');
        $this->assertNull($resolved4);
    }

    public function test_counterparty_resolve_via_api(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->postJson('/api/v1/counterparties/resolve', [
            'name' => 'Adm Bank',
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('BANK-BRI', $response->json('data.code'));
    }

    public function test_fund_allocation_and_negative_balance_guard(): void
    {
        TenantContext::setTenantId($this->orgA->id);

        $fund = Fund::where('organization_id', $this->orgA->id)->where('code', 'DANA-RAMADHAN')->firstOrFail();
        $period = \App\Domain\Accounting\Models\FiscalPeriod::where('organization_id', $this->orgA->id)->firstOrFail();

        // 1. Create Allocation Rp10.000.000
        $allocation = $this->fundService->createAllocation([
            'organization_id' => $this->orgA->id,
            'fund_id' => $fund->id,
            'fiscal_period_id' => $period->id,
            'allocated_amount' => 10000000,
        ], $this->adminA);

        $this->assertEquals(10000000, (float) $allocation->allocated_amount);
        $this->assertEquals(10000000, (float) $allocation->available_amount);

        // 2. Commit Rp4.000.000 -> Available becomes Rp6.000.000
        $this->fundService->commit($allocation, 4000000, $this->adminA);
        $allocation->refresh();
        $this->assertEquals(6000000, (float) $allocation->available_amount);

        // 3. Disburse Rp3.000.000 -> Available becomes Rp3.000.000
        $this->fundService->disburse($allocation, 3000000, false, $this->adminA);
        $allocation->refresh();
        $this->assertEquals(3000000, (float) $allocation->available_amount);

        // 4. Over-disbursement guard: Attempt to disburse Rp5.000.000 when only Rp3.000.000 is available
        $this->expectException(NegativeFundBalanceException::class);
        $this->fundService->disburse($allocation, 5000000, false, $this->adminA);
    }

    public function test_fund_disbursement_guard_via_api(): void
    {
        Sanctum::actingAs($this->adminA);

        $fund = Fund::where('organization_id', $this->orgA->id)->where('code', 'DANA-BEASISWA')->firstOrFail();
        $period = \App\Domain\Accounting\Models\FiscalPeriod::where('organization_id', $this->orgA->id)->firstOrFail();

        $allocRes = $this->postJson("/api/v1/funds/{$fund->id}/allocate", [
            'fiscal_period_id' => $period->id,
            'allocated_amount' => 5000000,
            'notes' => 'Alokasi Semester Genap',
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);
        $allocRes->assertStatus(201);
        $allocationId = $allocRes->json('data.id');

        // Disburse more than allocated amount -> Expect 422 with NEGATIVE_FUND_BALANCE
        $disburseRes = $this->postJson("/api/v1/funds/allocations/{$allocationId}/disburse", [
            'amount' => 7000000,
            'description' => 'Pencairan Beasiswa Berlebih',
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $disburseRes->assertStatus(422);
        $disburseRes->assertJsonFragment(['error' => 'NEGATIVE_FUND_BALANCE']);
    }

    public function test_program_crud_via_api(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->postJson('/api/v1/programs', [
            'code' => 'PROG-SARPRAS',
            'name' => 'Program Bantuan Sarana Prasarana',
            'description' => 'Rehab gedung madrasah',
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(201);
        $this->assertEquals('PROG-SARPRAS', $response->json('data.code'));
    }
}
