<?php

namespace Tests\Feature;

use App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domain\Accounting\Exceptions\InactiveAccountException;
use App\Domain\Accounting\Exceptions\InvalidWorkflowStateException;
use App\Domain\Accounting\Exceptions\NonPostableAccountException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\BankTransferPostingCoordinator;
use App\Domain\Accounting\Services\BusinessTransactionPostingCoordinator;
use App\Domain\Accounting\Services\FiscalPeriodService;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\UnappliedCashHandler;
use App\Domain\Bank\Enums\BankTransactionDirection;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Database\Seeders\CoaSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityHardeningAndTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $admin;

    private User $operator;

    private User $approver;

    private User $plainUser;

    private FiscalPeriod $openPeriodA;

    private Account $cashAccountA;

    private Account $revenueAccountA;

    private Account $cashAccountB;

    private BankAccount $bankAccountA;

    private BankAccount $bankAccountA2;

    private BankAccount $bankAccountB;

    private Counterparty $counterpartyA;

    private Counterparty $counterpartyB;

    private FiscalPeriodService $fiscalPeriodService;

    private JournalPostingService $journalPostingService;

    private BusinessTransactionPostingCoordinator $postingCoordinator;

    private BankTransferPostingCoordinator $transferCoordinator;

    private UnappliedCashHandler $unappliedCashHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OrganizationSeeder::class);
        $this->seed(CoaSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $this->orgA = Organization::where('code', 'LP-MAARIF-CLP')->firstOrFail();

        // Create Org B fixtures with scoping bypassed during fixture setup
        TenantContext::setBypassScoping(true);

        $this->orgB = Organization::create([
            'code' => 'ORG-TEST-B',
            'name' => 'Organisasi Test B',
            'type' => 'madrasah',
            'is_active' => true,
        ]);

        $this->cashAccountB = Account::create([
            'organization_id' => $this->orgB->id,
            'code' => '1110-B',
            'name' => 'Kas Org B',
            'account_type' => Account::TYPE_ASSET,
            'normal_balance' => Account::BALANCE_DEBIT,
            'is_postable' => true,
            'is_active' => true,
        ]);

        $this->bankAccountB = BankAccount::create([
            'organization_id' => $this->orgB->id,
            'bank_name' => 'BRI Cabang Org B',
            'account_number' => '1122334455',
            'account_name' => 'Madrasah B Account',
            'branch' => 'Cilacap',
            'type' => 'giro',
            'account_id' => $this->cashAccountB->id,
            'is_active' => true,
        ]);

        $this->counterpartyB = Counterparty::create([
            'organization_id' => $this->orgB->id,
            'code' => 'CP-ORG-B',
            'name' => 'Vendor Org B',
            'role' => Counterparty::ROLE_VENDOR,
            'is_active' => true,
        ]);

        TenantContext::setBypassScoping(false);
        TenantContext::setTenantId($this->orgA->id);

        $this->admin = User::where('email', 'admin@maarif-cilacap.org')->firstOrFail();
        $this->operator = User::where('email', 'operator@maarif-cilacap.org')->firstOrFail();
        $this->approver = User::where('email', 'approver@maarif-cilacap.org')->firstOrFail();

        $this->plainUser = User::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Plain User Without Permissions',
            'email' => 'plain.user@maarif.test',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->openPeriodA = FiscalPeriod::where('organization_id', $this->orgA->id)
            ->where('code', '2026-03')
            ->firstOrFail();

        $this->cashAccountA = Account::where('organization_id', $this->orgA->id)
            ->where('code', '1110')
            ->firstOrFail();

        $this->revenueAccountA = Account::where('organization_id', $this->orgA->id)
            ->where('code', '4100')
            ->firstOrFail();

        $this->bankAccountA = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%308%')
            ->firstOrFail();

        $this->bankAccountA2 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%304%')
            ->firstOrFail();

        $this->counterpartyA = Counterparty::where('organization_id', $this->orgA->id)->firstOrFail();

        $this->fiscalPeriodService = app(FiscalPeriodService::class);
        $this->journalPostingService = app(JournalPostingService::class);
        $this->postingCoordinator = app(BusinessTransactionPostingCoordinator::class);
        $this->transferCoordinator = app(BankTransferPostingCoordinator::class);
        $this->unappliedCashHandler = app(UnappliedCashHandler::class);
    }

    protected function tearDown(): void
    {
        TenantContext::setTenantId(null);
        parent::tearDown();
    }

    public function test_inactive_user_is_rejected_at_middleware_with_http_403_account_inactive(): void
    {
        $inactiveUser = User::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Disabled User',
            'email' => 'disabled@maarif.test',
            'password' => bcrypt('password123'),
            'is_active' => false,
        ]);

        Sanctum::actingAs($inactiveUser);

        $response = $this->getJson('/api/v1/business-transactions/pending-posting');

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'ACCOUNT_INACTIVE',
        ]);
    }

    public function test_user_of_inactive_organization_is_rejected_at_middleware_with_http_403_organization_inactive(): void
    {
        $deactivatedOrg = Organization::create([
            'code' => 'DEACTIVATED-ORG',
            'name' => 'Organisasi Nonaktif',
            'type' => 'madrasah',
            'is_active' => false,
        ]);

        $userOfDeactivatedOrg = User::create([
            'organization_id' => $deactivatedOrg->id,
            'name' => 'User of Deactivated Org',
            'email' => 'user.deactivated@maarif.test',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        Sanctum::actingAs($userOfDeactivatedOrg);

        $response = $this->getJson('/api/v1/business-transactions/pending-posting');

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'ORGANIZATION_INACTIVE',
        ]);
    }

    public function test_cross_tenant_access_attempt_with_mismatched_header_is_rejected_with_cross_tenant_violation(): void
    {
        Sanctum::actingAs($this->operator);

        // Operator of Org A attempts to request data with Org B header
        $response = $this->withHeaders([
            'X-Organization-ID' => (string) $this->orgB->id,
        ])->getJson('/api/v1/business-transactions/pending-posting');

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'error' => 'CROSS_TENANT_VIOLATION',
        ]);
    }

    public function test_unapplied_cash_resolution_rejects_cross_tenant_target_account(): void
    {
        $tx = $this->createTestTransaction($this->orgA->id, 'UNAPPLIED_CASH', 'IN', '500000.00');

        $this->expectException(CrossTenantViolationException::class);

        $this->unappliedCashHandler->resolve(
            transaction: $tx,
            resolutionData: [
                'target_account_id' => $this->cashAccountB->id, // Belongs to Org B!
            ],
            resolver: $this->approver
        );
    }

    public function test_unapplied_cash_resolution_rejects_inactive_target_account(): void
    {
        $inactiveAccount = Account::create([
            'organization_id' => $this->orgA->id,
            'code' => '4999-INACTIVE',
            'name' => 'Pendapatan Nonaktif',
            'account_type' => Account::TYPE_REVENUE,
            'normal_balance' => Account::BALANCE_CREDIT,
            'is_postable' => true,
            'is_active' => false,
        ]);

        $tx = $this->createTestTransaction($this->orgA->id, 'UNAPPLIED_CASH', 'IN', '500000.00');

        $this->expectException(InactiveAccountException::class);

        $this->unappliedCashHandler->resolve(
            transaction: $tx,
            resolutionData: [
                'target_account_id' => $inactiveAccount->id,
            ],
            resolver: $this->approver
        );
    }

    public function test_unapplied_cash_resolution_rejects_non_postable_header_target_account(): void
    {
        $headerAccount = Account::where('organization_id', $this->orgA->id)
            ->where('is_postable', false)
            ->first();

        if (! $headerAccount) {
            $headerAccount = Account::create([
                'organization_id' => $this->orgA->id,
                'code' => '4000-HDR',
                'name' => 'Header Pendapatan',
                'account_type' => Account::TYPE_REVENUE,
                'normal_balance' => Account::BALANCE_CREDIT,
                'is_postable' => false,
                'is_active' => true,
            ]);
        }

        $tx = $this->createTestTransaction($this->orgA->id, 'UNAPPLIED_CASH', 'IN', '500000.00');

        $this->expectException(NonPostableAccountException::class);

        $this->unappliedCashHandler->resolve(
            transaction: $tx,
            resolutionData: [
                'target_account_id' => $headerAccount->id,
            ],
            resolver: $this->approver
        );
    }

    public function test_unapplied_cash_resolution_rejects_cross_tenant_counterparty(): void
    {
        $tx = $this->createTestTransaction($this->orgA->id, 'UNAPPLIED_CASH', 'IN', '500000.00');

        $this->expectException(CrossTenantViolationException::class);

        $this->unappliedCashHandler->resolve(
            transaction: $tx,
            resolutionData: [
                'counterparty_id' => $this->counterpartyB->id, // Belongs to Org B!
            ],
            resolver: $this->approver
        );
    }

    public function test_unapplied_cash_resolution_rejects_period_locked_transaction(): void
    {
        $tx = $this->createTestTransaction($this->orgA->id, 'UNAPPLIED_CASH', 'IN', '500000.00');
        $tx->is_period_locked = true;
        $tx->save();

        $this->expectException(ClosedFiscalPeriodException::class);

        $this->unappliedCashHandler->resolve(
            transaction: $tx,
            resolutionData: [
                'target_account_id' => $this->revenueAccountA->id,
            ],
            resolver: $this->approver
        );
    }

    public function test_unapplied_cash_resolution_rejects_unauthorized_resolver_without_review_or_approve_permission(): void
    {
        $tx = $this->createTestTransaction($this->orgA->id, 'UNAPPLIED_CASH', 'IN', '500000.00');

        $this->expectException(AuthorizationException::class);

        $this->unappliedCashHandler->resolve(
            transaction: $tx,
            resolutionData: [
                'target_account_id' => $this->revenueAccountA->id,
            ],
            resolver: $this->plainUser
        );
    }

    public function test_unapplied_cash_resolution_rejects_reclassification_to_bank_transfer(): void
    {
        $tx = $this->createTestTransaction($this->orgA->id, 'UNAPPLIED_CASH', 'IN', '500000.00');

        $this->expectException(InvalidWorkflowStateException::class);

        $this->unappliedCashHandler->resolve(
            transaction: $tx,
            resolutionData: [
                'transaction_type' => 'BANK_TRANSFER',
            ],
            resolver: $this->approver
        );
    }

    public function test_bank_transfer_pairing_rejects_cross_tenant_transactions(): void
    {
        $txA = $this->createTestTransaction($this->orgA->id, 'BANK_TRANSFER', 'OUT', '1000000.00', $this->bankAccountA->id);

        TenantContext::setBypassScoping(true);
        $txB = $this->createTestTransaction($this->orgB->id, 'BANK_TRANSFER', 'IN', '1000000.00', $this->bankAccountB->id);
        TenantContext::setBypassScoping(false);
        TenantContext::setTenantId($this->orgA->id);

        $this->expectException(CrossTenantViolationException::class);

        $this->transferCoordinator->pair($txA, $txB, $this->approver);
    }

    public function test_bank_transfer_pairing_rejects_period_locked_transactions(): void
    {
        $txA = $this->createTestTransaction($this->orgA->id, 'BANK_TRANSFER', 'OUT', '1000000.00', $this->bankAccountA->id);
        $txB = $this->createTestTransaction($this->orgA->id, 'BANK_TRANSFER', 'IN', '1000000.00', $this->bankAccountA2->id);

        $txA->is_period_locked = true;
        $txA->save();

        $this->expectException(ClosedFiscalPeriodException::class);

        $this->transferCoordinator->pair($txA, $txB, $this->approver);
    }

