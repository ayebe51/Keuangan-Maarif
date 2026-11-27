<?php

namespace Tests\Feature;

use App\Domain\Accounting\Enums\TransferPairingStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntrySource;
use App\Domain\Accounting\Services\BankTransferPostingCoordinator;
use App\Domain\Bank\Enums\BankTransactionDirection;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Models\BusinessTransaction;
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

class BankTransferAndUnappliedCashApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private User $admin;

    private User $operator;

    private User $approver;

    private User $plainUser;

    private BankAccount $bankGiro308;

    private BankAccount $bankGiro304;

    private Account $accUnapplied2200;

    private Account $accPendapatan4100;

    private Counterparty $cpInternalMaarif;

    private FiscalPeriod $periodMaret2026;

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
            'email' => 'plain_trf@maarif-cilacap.org',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->bankGiro308 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%308%')->firstOrFail();

        $this->bankGiro304 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%304%')->firstOrFail();

        $this->accUnapplied2200 = Account::where('organization_id', $this->orgA->id)
            ->where('code', '2200')->firstOrFail();

        $this->accPendapatan4100 = Account::where('organization_id', $this->orgA->id)
            ->where('code', '4100')->firstOrFail();

        $this->cpInternalMaarif = Counterparty::where('organization_id', $this->orgA->id)
            ->where('code', 'INTERNAL-MAARIF')->firstOrFail();

        $this->periodMaret2026 = FiscalPeriod::where('organization_id', $this->orgA->id)
            ->where('code', '2026-03')->firstOrFail();
    }

    protected function tearDown(): void
    {
        TenantContext::setTenantId(null);
        parent::tearDown();
    }

    private function createTransferTransaction(BankAccount $bankAccount, string $direction, float $amount): BusinessTransaction
    {
        $bankTx = BankTransaction::create([
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $bankAccount->id,
            'transaction_date' => '2026-03-15',
            'value_date' => '2026-03-15',
            'description' => 'Transfer internal bank test',
            'reference_number' => 'REF-API-TRF-'.uniqid(),
            'direction' => $direction === 'OUT' ? BankTransactionDirection::OUT : BankTransactionDirection::IN,
            'amount' => $amount,
            'fingerprint' => hash('sha256', uniqid('fp_api_trf_', true)),
            'balance_after' => 10000000.00,
        ]);

        return BusinessTransaction::create([
            'organization_id' => $this->orgA->id,
            'transaction_number' => BusinessTransaction::generateTransactionNumber($this->orgA->id, 2026),
            'bank_transaction_id' => $bankTx->id,
            'fiscal_period_id' => $this->periodMaret2026->id,
            'transaction_date' => '2026-03-15',
            'value_date' => '2026-03-15',
            'transaction_type' => 'BANK_TRANSFER',
            'direction' => $direction,
            'amount' => $amount,
            'counterparty_id' => $this->cpInternalMaarif->id,
            'classification_status' => ClassificationStatus::CLASSIFIED->value,
            'transfer_pairing_status' => TransferPairingStatus::PENDING_PAIRING->value,
            'auto_post_eligible' => true,
            'description' => 'Transfer internal bank test',
            'status' => 'draft',
        ]);
    }

    public function test_transfer_and_unapplied_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/business-transactions/1/pair-transfer')->assertStatus(401);
        $this->postJson('/api/v1/business-transactions/1/unpair-transfer')->assertStatus(401);
        $this->postJson('/api/v1/business-transactions/detect-transfer-pairs')->assertStatus(401);
        $this->getJson('/api/v1/business-transactions/transfer-queue')->assertStatus(401);
        $this->getJson('/api/v1/business-transactions/unapplied-cash-queue')->assertStatus(401);
        $this->postJson('/api/v1/business-transactions/1/resolve-unapplied-cash')->assertStatus(401);
    }

    public function test_pair_transfer_endpoint_requires_proper_permissions(): void
    {
        Sanctum::actingAs($this->plainUser);
        $this->postJson('/api/v1/business-transactions/1/pair-transfer', [
            'paired_transaction_id' => 2,
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ])->assertStatus(403);
    }

    public function test_pair_transfer_endpoint_successfully_pairs_two_transactions(): void
    {
        Sanctum::actingAs($this->approver);

        $legOut = $this->createTransferTransaction($this->bankGiro308, 'OUT', 5000000.00);
        $legIn = $this->createTransferTransaction($this->bankGiro304, 'IN', 5000000.00);

        $response = $this->postJson("/api/v1/business-transactions/{$legOut->id}/pair-transfer", [
            'paired_transaction_id' => $legIn->id,
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'PAIRED');
        $response->assertJsonPath('data.source_transaction_id', $legOut->id);
        $response->assertJsonPath('data.destination_transaction_id', $legIn->id);

        $this->assertTrue($legOut->fresh()->isTransferPaired());
        $this->assertTrue($legIn->fresh()->isTransferPaired());
        $this->assertSame($legIn->id, $legOut->fresh()->paired_business_transaction_id);
        $this->assertSame($legOut->id, $legIn->fresh()->paired_business_transaction_id);
    }

    public function test_unpair_transfer_endpoint_successfully_unpairs(): void
    {
        Sanctum::actingAs($this->approver);

        $legOut = $this->createTransferTransaction($this->bankGiro308, 'OUT', 2000000.00);
        $legIn = $this->createTransferTransaction($this->bankGiro304, 'IN', 2000000.00);

        app(BankTransferPostingCoordinator::class)->pair($legOut, $legIn, $this->approver);

        $response = $this->postJson("/api/v1/business-transactions/{$legOut->id}/unpair-transfer", [], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(200);
        $this->assertSame(TransferPairingStatus::PENDING_PAIRING->value, $legOut->fresh()->transfer_pairing_status);
        $this->assertSame(TransferPairingStatus::PENDING_PAIRING->value, $legIn->fresh()->transfer_pairing_status);
        $this->assertNull($legOut->fresh()->paired_business_transaction_id);
        $this->assertNull($legIn->fresh()->paired_business_transaction_id);
    }

    public function test_detect_transfer_pairs_endpoint(): void
    {
        Sanctum::actingAs($this->approver);

        $this->createTransferTransaction($this->bankGiro308, 'OUT', 3500000.00);
        $this->createTransferTransaction($this->bankGiro304, 'IN', 3500000.00);

        $response = $this->postJson('/api/v1/business-transactions/detect-transfer-pairs', [], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'data' => [
                'total_checked',
                'paired_count',
                'ambiguous_count',
                'pending_count',
            ],
        ]);
        $this->assertSame(1, $response->json('data.paired_count'));
    }

    public function test_transfer_review_queue_endpoint(): void
    {
        Sanctum::actingAs($this->approver);

        $tx = $this->createTransferTransaction($this->bankGiro308, 'OUT', 1200000.00);
        $tx->transfer_pairing_status = TransferPairingStatus::PAIRING_AMBIGUOUS->value;
        $tx->save();

        $response = $this->getJson('/api/v1/business-transactions/transfer-queue', [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('total'));
    }

    public function test_unapplied_cash_queue_and_resolution(): void
    {
        Sanctum::actingAs($this->approver);

        $bankTx = BankTransaction::create([
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $this->bankGiro308->id,
            'transaction_date' => '2026-03-15',
            'value_date' => '2026-03-15',
            'description' => 'Titipan belum jelas API test',
            'reference_number' => 'REF-API-UNAPP',
            'direction' => BankTransactionDirection::IN,
            'amount' => 500000.00,
            'fingerprint' => hash('sha256', uniqid('fp_api_unapp_', true)),
            'balance_after' => 10000000.00,
        ]);

        $tx = BusinessTransaction::create([
            'organization_id' => $this->orgA->id,
            'transaction_number' => BusinessTransaction::generateTransactionNumber($this->orgA->id, 2026),
            'bank_transaction_id' => $bankTx->id,
            'fiscal_period_id' => $this->periodMaret2026->id,
            'transaction_date' => '2026-03-15',
            'value_date' => '2026-03-15',
            'transaction_type' => 'UNAPPLIED_CASH',
            'direction' => 'IN',
            'amount' => 500000.00,
            'target_account_id' => $this->accUnapplied2200->id,
            'classification_status' => ClassificationStatus::CLASSIFIED->value,
            'description' => 'Dana titipan belum jelas',
            'status' => 'draft',
        ]);

        // 1. Inspect queue
        $queueResponse = $this->getJson('/api/v1/business-transactions/unapplied-cash-queue', [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);
        $queueResponse->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $queueResponse->json('total'));

        // 2. Resolve via endpoint
        $resolveResponse = $this->postJson("/api/v1/business-transactions/{$tx->id}/resolve-unapplied-cash", [
            'target_account_id' => $this->accPendapatan4100->id,
            'transaction_type' => 'DIRECT_INCOME',
            'notes' => 'Telah diidentifikasi sebagai iuran madrasah',
        ], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $resolveResponse->assertStatus(200);
        $this->assertSame($this->accPendapatan4100->id, $tx->fresh()->target_account_id);
        $this->assertSame('DIRECT_INCOME', $tx->fresh()->transaction_type);
        $this->assertSame(ClassificationStatus::REVIEWED->value, $tx->fresh()->classification_status->value);
    }

    public function test_end_to_end_pair_draft_and_approve_post_2_to_1_transfer(): void
    {
        $legOut = $this->createTransferTransaction($this->bankGiro308, 'OUT', 6000000.00);
        $legIn = $this->createTransferTransaction($this->bankGiro304, 'IN', 6000000.00);

        // 1. Pair transactions
        $coordinator = app(BankTransferPostingCoordinator::class);
        $coordinator->pair($legOut, $legIn, $this->operator);

        // 2. Generate 2:1 compound draft
        $draft = $coordinator->createTransferJournalDraft($legOut, $this->operator);
        $this->assertSame(JournalEntry::STATUS_DRAFT, $draft->status);

        // 3. Approve and post via API by checker ($this->approver != $this->operator)
        Sanctum::actingAs($this->approver);
        $response = $this->postJson("/api/v1/business-transactions/{$legOut->id}/approve-and-post", [], [
            'X-Tenant-ID' => (string) $this->orgA->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.journal_entry_id', $draft->id);

        // 4. Verify BOTH legs transitioned to POSTED and point to the same journal
        $legOutFresh = $legOut->fresh();
        $legInFresh = $legIn->fresh();

        $this->assertSame('posted', $legOutFresh->status);
        $this->assertSame('posted', $legInFresh->status);
        $this->assertSame($draft->id, (int) $legOutFresh->posted_journal_entry_id);
        $this->assertSame($draft->id, (int) $legInFresh->posted_journal_entry_id);

        // 5. Verify journal posted and 2:1 sources intact
        $postedJournal = JournalEntry::find($draft->id);
        $this->assertSame('posted', $postedJournal->status);
        $this->assertSame($this->approver->id, $postedJournal->posted_by);

        $sources = JournalEntrySource::where('journal_entry_id', $draft->id)->get();
        $this->assertCount(2, $sources);
        $this->assertNotNull($sources->firstWhere('leg_role', JournalEntrySource::ROLE_TRANSFER_SOURCE));
        $this->assertNotNull($sources->firstWhere('leg_role', JournalEntrySource::ROLE_TRANSFER_DESTINATION));
    }
}
