<?php

namespace Tests\Unit;

use App\Domain\Accounting\Exceptions\InvalidWorkflowStateException;
use App\Domain\Accounting\Exceptions\SegregationOfDutiesException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\BusinessTransactionPostingCoordinator;
use App\Domain\Accounting\Services\JournalDraftGenerator;
use App\Domain\Bank\Enums\BankTransactionDirection;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Fund\Models\Fund;
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
use Tests\TestCase;

class BusinessTransactionPostingCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $maker;

    private User $approver;

    private User $admin;

    private User $plainUser;

    private FiscalPeriod $periodMaret2026;

    private BankAccount $bankGiro308;

    private Counterparty $cpDarwata;

    private Fund $fundAbadi;

    private BusinessTransactionPostingCoordinator $coordinator;

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

        $this->maker = User::where('email', 'operator@maarif-cilacap.org')->firstOrFail();
        $this->approver = User::where('email', 'approver@maarif-cilacap.org')->firstOrFail();
        $this->admin = User::where('email', 'admin@maarif-cilacap.org')->firstOrFail();

        $this->plainUser = User::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Plain User Without Permissions',
            'email' => 'plain@maarif-cilacap.org',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->bankGiro308 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%308%')->firstOrFail();

        $this->cpDarwata = Counterparty::where('organization_id', $this->orgA->id)
            ->where('name', 'LIKE', '%Darwata%')->firstOrFail();

        $this->fundAbadi = Fund::where('organization_id', $this->orgA->id)
            ->where('code', 'DANA-ABADI')->firstOrFail();

        $this->periodMaret2026 = FiscalPeriod::where('organization_id', $this->orgA->id)
            ->where('start_date', '<=', '2026-03-15')
            ->where('end_date', '>=', '2026-03-15')
            ->firstOrFail();

        $this->coordinator = app(BusinessTransactionPostingCoordinator::class);
        $this->draftGenerator = app(JournalDraftGenerator::class);
    }

    protected function tearDown(): void
    {
        TenantContext::setTenantId(null);
        parent::tearDown();
    }

    private function createSampleBusinessTransaction(string $type = 'DIRECT_INCOME', float $amount = 1500000.00): BusinessTransaction
    {
        $bankTx = BankTransaction::create([
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $this->bankGiro308->id,
            'transaction_date' => '2026-03-15',
            'value_date' => '2026-03-15',
            'description' => 'Penerimaan infaq pembangunan direct income',
            'reference_number' => 'REF-UNIT-'.uniqid(),
            'direction' => BankTransactionDirection::IN,
            'amount' => $amount,
            'fingerprint' => hash('sha256', uniqid('fp_', true)),
            'balance_after' => 10000000.00,
        ]);

        $pendapatanAccount = Account::where('organization_id', $this->orgA->id)
            ->where('code', '4100')
            ->firstOrFail();

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
            'target_account_id' => $pendapatanAccount->id,
            'fund_id' => $this->fundAbadi->id,
            'classification_status' => ClassificationStatus::CLASSIFIED->value,
            'auto_post_eligible' => false,
            'description' => 'Test Direct Income Unit',
            'status' => 'draft',
        ]);
    }

    public function test_approve_and_post_succeeds_when_checker_is_different_from_maker(): void
    {
        $tx = $this->createSampleBusinessTransaction();

        // 1. Generate Draft Journal by $maker
        $draft = $this->draftGenerator->generate($tx, $this->maker);
        $this->assertSame(JournalEntry::STATUS_DRAFT, $draft->status);
        $this->assertSame($this->maker->id, $draft->created_by);

        // 2. Approve and Post by $approver
        $response = $this->coordinator->approveAndPost($tx, $this->approver);

        $this->assertTrue($response['success']);
        $this->assertFalse($response['is_duplicate']);
        $this->assertSame('Transaksi berhasil disetujui dan diposting ke buku besar.', $response['message']);

        // Verify transaction updated
        $tx->refresh();
        $this->assertSame('posted', $tx->status);
        $this->assertSame($draft->id, $tx->posted_journal_entry_id);
        $this->assertSame($this->approver->id, $tx->posted_by);
        $this->assertNotNull($tx->posted_at);

        // Verify journal entry updated
        $draft->refresh();
        $this->assertSame(JournalEntry::STATUS_POSTED, $draft->status);
        $this->assertSame($this->approver->id, $draft->posted_by);
    }

    public function test_approve_and_post_enforces_sod_when_maker_equals_checker(): void
    {
        $tx = $this->createSampleBusinessTransaction();
        // Approver user creates draft, then attempts to approve own draft
        $draft = $this->draftGenerator->generate($tx, $this->approver);
        $this->assertSame($this->approver->id, $draft->created_by);

        // Approver attempts to approve their own draft -> Must Throw SegregationOfDutiesException
        $this->expectException(SegregationOfDutiesException::class);
        $this->coordinator->approveAndPost($tx, $this->approver);
    }

    public function test_approve_and_post_is_idempotent_when_already_posted(): void
    {
        $tx = $this->createSampleBusinessTransaction();
        $draft = $this->draftGenerator->generate($tx, $this->maker);

        // First Post
        $first = $this->coordinator->approveAndPost($tx, $this->approver);
        $this->assertTrue($first['success']);
        $this->assertFalse($first['is_duplicate']);

        $totalJournalsBefore = JournalEntry::where('organization_id', $this->orgA->id)->count();

        // Re-post already posted transaction
        $second = $this->coordinator->approveAndPost($tx, $this->approver);
        $this->assertTrue($second['success']);
        $this->assertTrue($second['is_duplicate']);
        $this->assertSame('Transaksi ini telah diposting sebelumnya ke buku besar.', $second['message']);
        $this->assertSame($draft->id, $second['data']['journal_entry_id']);

        // Invariant: Zero duplicate journals created
        $this->assertSame($totalJournalsBefore, JournalEntry::where('organization_id', $this->orgA->id)->count());
    }

    public function test_approve_and_post_throws_when_transaction_is_rejected(): void
    {
        $tx = $this->createSampleBusinessTransaction();
        $tx->status = 'rejected';
        $tx->save();

        $this->expectException(InvalidWorkflowStateException::class);
        $this->expectExceptionMessage("Cannot approve rejected transaction {$tx->transaction_number}.");

        $this->coordinator->approveAndPost($tx, $this->approver);
    }

    public function test_approve_and_post_throws_when_no_draft_exists(): void
    {
        $tx = $this->createSampleBusinessTransaction();
        // No draft generated

        $this->expectException(InvalidWorkflowStateException::class);
        $this->expectExceptionMessage('does not have an active journal draft to approve');

        $this->coordinator->approveAndPost($tx, $this->approver);
    }

    public function test_approve_and_post_enforces_tenant_isolation(): void
    {
        $tx = $this->createSampleBusinessTransaction();
        $this->draftGenerator->generate($tx, $this->maker);

        TenantContext::setTenantId($this->orgB->id);

        try {
            $this->expectException(CrossTenantViolationException::class);
            $this->coordinator->approveAndPost($tx, $this->approver);
        } finally {
            TenantContext::setTenantId($this->orgA->id);
        }
    }

    public function test_approve_and_post_enforces_rbac_permissions(): void
    {
        $tx = $this->createSampleBusinessTransaction();
        $this->draftGenerator->generate($tx, $this->maker);

        // Plain user without journal.approve and journal.post
        $this->expectException(AuthorizationException::class);
        $this->coordinator->approveAndPost($tx, $this->plainUser);
    }

    public function test_reject_succeeds_and_cleans_up_draft_journal(): void
    {
        $tx = $this->createSampleBusinessTransaction();
        $draft = $this->draftGenerator->generate($tx, $this->maker);

        $this->assertDatabaseHas('journal_entries', ['id' => $draft->id, 'status' => 'draft']);
        $this->assertDatabaseHas('journal_entry_sources', ['journal_entry_id' => $draft->id]);

        $res = $this->coordinator->reject($tx, $this->approver, 'Mapping rekening tujuan tidak sesuai');

        $this->assertTrue($res['success']);
        $this->assertSame('rejected', $res['data']['status']);

        $tx->refresh();
        $this->assertSame('rejected', $tx->status);
        $this->assertStringContainsString('Rejected: Mapping rekening tujuan tidak sesuai', $tx->notes);
        $this->assertSame('HUMAN_REJECTED', $tx->posting_error['error_code']);
        $this->assertSame('Mapping rekening tujuan tidak sesuai', $tx->posting_error['message']);

        // Verify draft journal and its sources are deleted cleanly
        $this->assertDatabaseMissing('journal_entries', ['id' => $draft->id]);
        $this->assertDatabaseMissing('journal_entry_sources', ['journal_entry_id' => $draft->id]);
    }

    public function test_reject_throws_when_transaction_already_posted(): void
    {
        $tx = $this->createSampleBusinessTransaction();
        $this->draftGenerator->generate($tx, $this->maker);
        $this->coordinator->approveAndPost($tx, $this->approver);

        $this->expectException(InvalidWorkflowStateException::class);
        $this->expectExceptionMessage('Cannot reject an already posted transaction');

        $this->coordinator->reject($tx, $this->approver, 'Invalid reason');
    }

    public function test_reject_throws_when_transaction_already_rejected(): void
    {
        $tx = $this->createSampleBusinessTransaction();
        $this->coordinator->reject($tx, $this->approver, 'First rejection');

        $this->expectException(InvalidWorkflowStateException::class);
        $this->expectExceptionMessage("Transaction {$tx->transaction_number} is already rejected.");

        $this->coordinator->reject($tx, $this->approver, 'Second rejection');
    }

    public function test_reverse_succeeds_for_posted_transaction(): void
    {
        $tx = $this->createSampleBusinessTransaction();
        $draft = $this->draftGenerator->generate($tx, $this->maker);
        $this->coordinator->approveAndPost($tx, $this->approver);

        // Reverse using admin (has journal.reverse via RbacSeeder)
        $res = $this->coordinator->reverse($tx, $this->admin, 'Koreksi kesalahan input transaksi');

        $this->assertTrue($res['success']);
        $this->assertSame($draft->id, $res['data']['original_journal_entry_id']);

        $tx->refresh();
        $this->assertSame('reversed', $tx->status);
        $this->assertStringContainsString('Reversed by User', $tx->notes);

        // Verify original journal entry is marked reversed
        $draft->refresh();
        $this->assertSame(JournalEntry::STATUS_REVERSED, $draft->status);
        $this->assertNotNull($draft->reversed_entry_id);

        // Verify reversal journal entry created and posted
        $reversal = JournalEntry::find($res['data']['reversal_journal_entry_id']);
        $this->assertNotNull($reversal);
        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);
        $this->assertSame(JournalEntry::TYPE_REVERSAL, $reversal->entry_type);
    }

    public function test_reverse_throws_when_transaction_not_posted(): void
    {
        $tx = $this->createSampleBusinessTransaction();

        $this->expectException(InvalidWorkflowStateException::class);
        $this->expectExceptionMessage('Only POSTED transactions can be reversed.');

        $this->coordinator->reverse($tx, $this->admin, 'Premature reversal');
    }

    public function test_pending_posting_and_exception_query_scopes(): void
    {
        // 1. Pending transaction
        $txPending = $this->createSampleBusinessTransaction('DIRECT_INCOME', 500000.00);

        // 2. Exception transaction (has posting_error)
        $txException = $this->createSampleBusinessTransaction('DIRECT_INCOME', 750000.00);
        $txException->posting_error = [
            'error_code' => 'UNRESOLVED_ACCOUNT_MAPPING',
            'message' => 'Test mapping error',
        ];
        $txException->save();

        // 3. Posted transaction
        $txPosted = $this->createSampleBusinessTransaction('DIRECT_INCOME', 1000000.00);
        $this->draftGenerator->generate($txPosted, $this->maker);
        $this->coordinator->approveAndPost($txPosted, $this->approver);

        // Test Pending Query
        $pendingResults = $this->coordinator->getPendingPostingQuery($this->orgA->id)->pluck('id')->all();
        $this->assertContains($txPending->id, $pendingResults);
        $this->assertNotContains($txException->id, $pendingResults);
        $this->assertNotContains($txPosted->id, $pendingResults);

        // Test Exceptions Query
        $exceptionResults = $this->coordinator->getPostingExceptionsQuery($this->orgA->id)->pluck('id')->all();
        $this->assertContains($txException->id, $exceptionResults);
        $this->assertNotContains($txPending->id, $exceptionResults);
        $this->assertNotContains($txPosted->id, $exceptionResults);
    }
}
