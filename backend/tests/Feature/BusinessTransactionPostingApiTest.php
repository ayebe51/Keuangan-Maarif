<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Services\JournalDraftGenerator;
use App\Domain\Bank\Enums\BankTransactionDirection;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Fund\Models\Fund;
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

class BusinessTransactionPostingApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $admin;

    private User $operator;

    private User $approver;

    private User $plainUser;

    private BankAccount $bankGiro308;

    private Counterparty $cpDarwata;

    private Fund $fundAbadi;

    private FiscalPeriod $periodMaret2026;

    private Account $accPendapatan;

    private JournalDraftGenerator $draftGenerator;

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

        $this->admin = User::where('email', 'admin@maarif-cilacap.org')->firstOrFail();
        $this->operator = User::where('email', 'operator@maarif-cilacap.org')->firstOrFail();
        $this->approver = User::where('email', 'approver@maarif-cilacap.org')->firstOrFail();

        $this->plainUser = User::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Plain User No Permissions',
            'email' => 'plain-api@maarif-cilacap.org',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->bankGiro308 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%308%')->firstOrFail();

        $this->cpDarwata = Counterparty::where('organization_id', $this->orgA->id)
            ->where('name', 'LIKE', '%Darwata%')->firstOrFail();

        $this->fundAbadi = Fund::where('organization_id', $this->orgA->id)
            ->where('code', 'DANA-ABADI')->firstOrFail();

        $this->periodMaret2026 = FiscalPeriod::where('organization_id', $this->orgA->id)
            ->where('code', '2026-03')->firstOrFail();

        $this->accPendapatan = Account::where('organization_id', $this->orgA->id)
            ->where('code', '4100')->firstOrFail();

        $this->draftGenerator = app(JournalDraftGenerator::class);
    }

    protected function tearDown(): void
    {
        TenantContext::setTenantId(null);
        parent::tearDown();
    }

    private function createSampleTransaction(string $type = 'DIRECT_INCOME', float $amount = 2000000.00): BusinessTransaction
    {
        $bankTx = BankTransaction::create([
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $this->bankGiro308->id,
            'transaction_date' => '2026-03-15',
            'value_date' => '2026-03-15',
            'description' => 'Mutasi Bank Direct Income API',
            'reference_number' => 'REF-API-'.uniqid(),
            'direction' => BankTransactionDirection::IN,
            'amount' => $amount,
            'fingerprint' => hash('sha256', uniqid('fp_', true)),
            'balance_after' => 10000000.00,
        ]);

        return BusinessTransaction::create([
            'organization_id' => $this->orgA->id,
            'transaction_number' => BusinessTransaction::generateTransactionNumber($this->orgA->id, 2026),
            'bank_transaction_id' => $bankTx->id,
            'fiscal_period_id' => $this->periodMaret2026->id,
            'transaction_date' => '2026-03-15',
            'value_date' => '2026-03-15',
            'transaction_type' => $type,
            'direction' => 'IN',
            'amount' => $amount,
            'counterparty_id' => $this->cpDarwata->id,
            'target_account_id' => $this->accPendapatan->id,
            'fund_id' => $this->fundAbadi->id,
            'classification_status' => ClassificationStatus::CLASSIFIED->value,
            'auto_post_eligible' => false,
            'description' => 'Test Transaction API Direct Income',
            'status' => 'draft',
        ]);
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/business-transactions/pending-posting')->assertStatus(401);
        $this->getJson('/api/v1/business-transactions/posting-exceptions')->assertStatus(401);
        $this->postJson('/api/v1/business-transactions/1/approve-and-post')->assertStatus(401);
        $this->postJson('/api/v1/business-transactions/1/reject')->assertStatus(401);
        $this->postJson('/api/v1/business-transactions/1/reverse')->assertStatus(401);
    }

    public function test_pending_posting_endpoint_returns_paginated_transactions(): void
    {
        Sanctum::actingAs($this->approver);

        $tx = $this->createSampleTransaction();

        $response = $this->getJson('/api/v1/business-transactions/pending-posting', [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'current_page',
            'total',
            'per_page',
        ]);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($tx->id, $ids);
    }

    public function test_posting_exceptions_endpoint_returns_exceptions(): void
    {
        Sanctum::actingAs($this->approver);

        $tx = $this->createSampleTransaction();
        $tx->posting_error = [
            'error_code' => 'COA_NOT_FOUND',
            'message' => 'Account code 4199 not configured',
        ];
        $tx->save();

        $response = $this->getJson('/api/v1/business-transactions/posting-exceptions', [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'current_page',
            'total',
        ]);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($tx->id, $ids);
    }

    public function test_approve_and_post_api_success(): void
    {
        // 1. Operator generates draft
        $tx = $this->createSampleTransaction();
        $draft = $this->draftGenerator->generate($tx, $this->operator);

        // 2. Approver approves and posts via API
        Sanctum::actingAs($this->approver);

        $response = $this->postJson("/api/v1/business-transactions/{$tx->id}/approve-and-post", [], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'is_duplicate' => false,
            'message' => 'Transaksi berhasil disetujui dan diposting ke buku besar.',
            'data' => [
                'business_transaction_id' => $tx->id,
                'journal_entry_id' => $draft->id,
                'entry_number' => $draft->entry_number,
            ],
        ]);

        $tx->refresh();
        $this->assertSame('posted', $tx->status);
        $this->assertSame($draft->id, $tx->posted_journal_entry_id);
    }

    public function test_approve_and_post_api_idempotent_contract(): void
    {
        $tx = $this->createSampleTransaction();
        $draft = $this->draftGenerator->generate($tx, $this->operator);

        Sanctum::actingAs($this->approver);

        // First Post
        $first = $this->postJson("/api/v1/business-transactions/{$tx->id}/approve-and-post", [], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);
        $first->assertStatus(200);
        $first->assertJson(['is_duplicate' => false]);

        // Second Post (Idempotent replay)
        $second = $this->postJson("/api/v1/business-transactions/{$tx->id}/approve-and-post", [], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $second->assertStatus(200);
        $second->assertJson([
            'success' => true,
            'is_duplicate' => true,
            'message' => 'Transaksi ini telah diposting sebelumnya ke buku besar.',
            'data' => [
                'business_transaction_id' => $tx->id,
                'journal_entry_id' => $draft->id,
                'entry_number' => $draft->entry_number,
            ],
        ]);
    }

    public function test_approve_and_post_api_enforces_sod(): void
    {
        $tx = $this->createSampleTransaction();
        // Approver user generates the draft
        $draft = $this->draftGenerator->generate($tx, $this->approver);

        // Approver user attempts to approve their own draft via API
        Sanctum::actingAs($this->approver);

        $response = $this->postJson("/api/v1/business-transactions/{$tx->id}/approve-and-post", [], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'SEGREGATION_OF_DUTIES_VIOLATION',
        ]);
    }

    public function test_approve_and_post_api_enforces_rbac(): void
    {
        $tx = $this->createSampleTransaction();
        $this->draftGenerator->generate($tx, $this->operator);

        // Plain user without permissions
        Sanctum::actingAs($this->plainUser);

        $response = $this->postJson("/api/v1/business-transactions/{$tx->id}/approve-and-post", [], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_reject_api_success(): void
    {
        $tx = $this->createSampleTransaction();
        $draft = $this->draftGenerator->generate($tx, $this->operator);

        Sanctum::actingAs($this->approver);

        $response = $this->postJson("/api/v1/business-transactions/{$tx->id}/reject", [
            'reason' => 'Salah alokasi akun pendapatan',
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Transaksi berhasil ditolak dan dikembalikan ke antrean review.',
            'data' => [
                'business_transaction_id' => $tx->id,
                'status' => 'rejected',
                'reason' => 'Salah alokasi akun pendapatan',
            ],
        ]);

        $tx->refresh();
        $this->assertSame('rejected', $tx->status);
        $this->assertDatabaseMissing('journal_entries', ['id' => $draft->id]);
    }

    public function test_reject_api_validation_error(): void
    {
        $tx = $this->createSampleTransaction();

        Sanctum::actingAs($this->approver);

        // Missing reason
        $response = $this->postJson("/api/v1/business-transactions/{$tx->id}/reject", [], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason']);
    }

    public function test_reverse_api_success(): void
    {
        $tx = $this->createSampleTransaction();
        $draft = $this->draftGenerator->generate($tx, $this->operator);

        // Approve and post first
        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/business-transactions/{$tx->id}/approve-and-post", [], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ])->assertStatus(200);

        // Reverse using admin (has journal.reverse)
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/business-transactions/{$tx->id}/reverse", [
            'reason' => 'Kesalahan nominal transaksi masuk',
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Transaksi berhasil dibalik (reversed) di buku besar.',
            'data' => [
                'business_transaction_id' => $tx->id,
                'original_journal_entry_id' => $draft->id,
            ],
        ]);
        $this->assertNotEmpty($response->json('data.reversal_entry_number'));

        $tx->refresh();
        $this->assertSame('reversed', $tx->status);
    }

    public function test_reverse_api_fails_if_not_posted(): void
    {
        $tx = $this->createSampleTransaction();

        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/business-transactions/{$tx->id}/reverse", [
            'reason' => 'Reversal on unposted',
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'INVALID_WORKFLOW_STATE',
        ]);
    }
}
