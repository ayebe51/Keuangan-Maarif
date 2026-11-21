<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Bank\Enums\BankTransactionDirection;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Models\BankImport;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Models\TransactionCategory;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Database\Seeders\CoaSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutoPostBatchApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private User $admin;

    private User $operator;

    private User $approver;

    private User $plainUser;

    private BankAccount $bankGiro308;

    private Account $accountBebanPajak;

    private Counterparty $cpBankBri;

    private FiscalPeriod $periodMaret2026;

    private TransactionCategory $catPajakBunga;

    private BankImport $bankImport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OrganizationSeeder::class);
        $this->seed(CoaSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $this->orgA = Organization::where('code', 'LP-MAARIF-CLP')->firstOrFail();
        TenantContext::setTenantId($this->orgA->id);

        $this->admin = User::where('email', 'admin@maarif-cilacap.org')->firstOrFail();
        $this->operator = User::where('email', 'operator@maarif-cilacap.org')->firstOrFail();
        $this->approver = User::where('email', 'approver@maarif-cilacap.org')->firstOrFail();

        $this->plainUser = User::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Plain User Without Permissions',
            'email' => 'plain_api@maarif-cilacap.org',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->bankGiro308 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%308%')->firstOrFail();

        $this->accountBebanPajak = Account::where('organization_id', $this->orgA->id)
            ->where('code', '5200')->firstOrFail();

        $this->cpBankBri = Counterparty::where('organization_id', $this->orgA->id)
            ->where('name', 'LIKE', '%BRI%')->firstOrFail();

        $this->periodMaret2026 = FiscalPeriod::where('organization_id', $this->orgA->id)
            ->where('code', '2026-03')->firstOrFail();

        $this->catPajakBunga = TransactionCategory::where('organization_id', $this->orgA->id)
            ->where('code', 'OUT-PAJAK-BUNGA')->firstOrFail();

        $this->bankImport = BankImport::create([
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $this->bankGiro308->id,
            'imported_by' => $this->operator->id,
            'filename' => 'bri_maret_2026.csv',
            'format' => 'csv',
            'file_hash' => hash('sha256', 'dummy_import_api_test'),
            'total_rows' => 10,
            'status' => 'done',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::setTenantId(null);
        parent::tearDown();
    }

    private function createValidRule(array $overrides = []): ClassificationRule
    {
        $defaults = [
            'organization_id' => $this->orgA->id,
            'name' => 'Rule API AutoPost',
            'priority' => 10,
            'is_active' => true,
            'rule_type' => 'KEYWORD',
            'direction' => 'OUT',
            'bank_account_id' => $this->bankGiro308->id,
            'pattern' => 'PAJAK BUNGA',
            'pattern_type' => 'contains',
            'transaction_type' => 'BANK_FEE_TAX',
            'target_account_id' => $this->accountBebanPajak->id,
            'counterparty_id' => $this->cpBankBri->id,
            'auto_post' => true,
            'max_auto_post_amount' => '500000.00',
            'version' => 1,
            'created_by' => $this->admin->id,
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ];

        return ClassificationRule::create(array_merge($defaults, $overrides));
    }

    private function createValidTransaction(ClassificationRule $rule, array $overrides = []): BusinessTransaction
    {
        $bankTx = BankTransaction::create([
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $this->bankGiro308->id,
            'bank_import_id' => $this->bankImport->id,
            'transaction_date' => $overrides['transaction_date'] ?? '2026-03-15',
            'value_date' => $overrides['value_date'] ?? '2026-03-15',
            'description' => 'PAJAK BUNGA BANK MARET 2026',
            'reference_number' => 'REF-API-'.uniqid(),
            'direction' => BankTransactionDirection::OUT,
            'amount' => $overrides['amount'] ?? '25000.00',
            'fingerprint' => hash('sha256', uniqid('fp_api_', true)),
            'balance_after' => 10000000.00,
        ]);

        $defaults = [
            'organization_id' => $this->orgA->id,
            'transaction_number' => BusinessTransaction::generateTransactionNumber($this->orgA->id, 2026),
            'bank_transaction_id' => $bankTx->id,
            'fiscal_period_id' => $this->periodMaret2026->id,
            'transaction_date' => '2026-03-15',
            'value_date' => '2026-03-15',
            'transaction_type' => 'BANK_FEE_TAX',
            'direction' => 'OUT',
            'amount' => '25000.00',
            'transaction_category_id' => $this->catPajakBunga->id,
            'target_account_id' => $this->accountBebanPajak->id,
            'counterparty_id' => $this->cpBankBri->id,
            'classification_status' => ClassificationStatus::CLASSIFIED->value,
            'classification_method' => 'DETERMINISTIC_RULE',
            'auto_post_eligible' => true,
            'description' => 'Pajak Bunga Bank Maret 2026',
            'status' => 'draft',
            'rule_snapshot' => $rule->toSnapshot(),
        ];

        return BusinessTransaction::create(array_merge($defaults, $overrides));
    }

    public function test_auto_post_batch_requires_authentication(): void
    {
        $this->postJson('/api/v1/business-transactions/auto-post-batch')
            ->assertStatus(401);
    }

    public function test_auto_post_batch_forbidden_without_proper_role_or_permission(): void
    {
        // 1. Plain user with no roles
        Sanctum::actingAs($this->plainUser);
        $this->postJson('/api/v1/business-transactions/auto-post-batch', [], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ])->assertStatus(403);

        // 2. Operator has ACCOUNTING_OPERATOR (no journal.post)
        Sanctum::actingAs($this->operator);
        $this->postJson('/api/v1/business-transactions/auto-post-batch', [], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ])->assertStatus(403);
    }

    public function test_auto_post_batch_executes_eligible_transactions_with_accounting_admin(): void
    {
        Sanctum::actingAs($this->admin);

        $rule = $this->createValidRule();
        $tx1 = $this->createValidTransaction($rule, ['amount' => '15000.00']);
        $tx2 = $this->createValidTransaction($rule, ['amount' => '25000.00']);

        $response = $this->postJson('/api/v1/business-transactions/auto-post-batch', [
            'limit' => 50,
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'data' => [
                'organization_id',
                'total_candidates',
                'processed_count',
                'posted_count',
                'failed_count',
                'skipped_count',
                'results',
            ],
        ]);

        $data = $response->json('data');
        $this->assertSame(2, $data['total_candidates']);
        $this->assertSame(2, $data['posted_count']);
        $this->assertSame(0, $data['failed_count']);

        $this->assertSame('posted', $tx1->fresh()->status);
        $this->assertSame('posted', $tx2->fresh()->status);
        $this->assertNotNull($tx1->fresh()->posted_journal_entry_id);
        $this->assertNotNull($tx2->fresh()->posted_journal_entry_id);
    }

    public function test_auto_post_batch_respects_custom_limit(): void
    {
        Sanctum::actingAs($this->admin);

        $rule = $this->createValidRule();
        $this->createValidTransaction($rule, ['amount' => '10000.00']);
        $this->createValidTransaction($rule, ['amount' => '20000.00']);
        $this->createValidTransaction($rule, ['amount' => '30000.00']);

        $response = $this->postJson('/api/v1/business-transactions/auto-post-batch', [
            'limit' => 2,
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertSame(2, $data['total_candidates']);
        $this->assertSame(2, $data['posted_count']);
    }

    public function test_auto_post_batch_validates_limit_bounds(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/business-transactions/auto-post-batch', [
            'limit' => 0,
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ])->assertStatus(422);

        $this->postJson('/api/v1/business-transactions/auto-post-batch', [
            'limit' => 201,
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ])->assertStatus(422);
    }

    public function test_auto_post_batch_with_empty_queue(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/business-transactions/auto-post-batch', [], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertSame(0, $data['total_candidates']);
        $this->assertSame(0, $data['posted_count']);
        $this->assertSame(0, $data['processed_count']);
    }
}
