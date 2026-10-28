<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Bank\Enums\BankTransactionDirection;
use App\Domain\Bank\Enums\BankTransactionStatus;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Enums\BusinessTransactionType;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Exceptions\InvalidClassificationDirectionException;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Models\ClassificationSuggestion;
use App\Domain\Classification\Models\TransactionCategory;
use App\Domain\Classification\Services\TransactionClassificationService;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Counterparty\Services\CounterpartyService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClassificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;
    private Organization $orgB;
    private User $adminA;
    private User $adminB;
    private BankAccount $account308;
    private BankAccount $account304;
    private BankAccount $account538;
    private Counterparty $cpDarwata;
    private Counterparty $cpBri;
    private TransactionClassificationService $classificationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\CoaSeeder::class);
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $this->orgA = Organization::where('code', 'LP-MAARIF-CLP')->firstOrFail();
        $this->adminA = User::where('email', 'admin@maarif-cilacap.org')->firstOrFail();

        $this->orgB = Organization::create([
            'code' => 'ORG-TEST-B',
            'name' => 'Organisasi Test B',
            'type' => 'madrasah',
            'is_active' => true,
        ]);

        $this->adminB = User::create([
            'organization_id' => $this->orgB->id,
            'name' => 'Admin Org B',
            'email' => 'admin-b@test.org',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
        $this->adminB->assignRole('ACCOUNTING_ADMIN');

        TenantContext::setTenantId($this->orgA->id);

        $this->account308 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%308%')->firstOrFail();
        $this->account304 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%304%')->firstOrFail();
        $this->account538 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%538%')->firstOrFail();

        $this->cpDarwata = Counterparty::where('organization_id', $this->orgA->id)
            ->where('name', 'LIKE', '%Darwata%')->firstOrFail();
        $this->cpBri = Counterparty::where('organization_id', $this->orgA->id)
            ->where('code', 'BANK-BRI')->firstOrFail();

        $this->classificationService = app(TransactionClassificationService::class);
    }

    private function createBankTransaction(array $overrides = []): BankTransaction
    {
        $defaults = [
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $this->account538->id,
            'transaction_date' => '2026-03-10',
            'value_date' => '2026-03-10',
