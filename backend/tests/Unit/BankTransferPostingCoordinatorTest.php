<?php

namespace Tests\Unit;

use App\Domain\Accounting\Enums\TransferPairingStatus;
use App\Domain\Accounting\Exceptions\InvalidTransferPairingException;
use App\Domain\Accounting\Exceptions\UnpairedTransferException;
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
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Database\Seeders\CoaSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankTransferPostingCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $maker;

    private User $approver;

    private BankAccount $bankGiro308;

    private BankAccount $bankGiro304;

    private BankAccount $bankTab538;

    private Counterparty $cpInternalMaarif;

    private FiscalPeriod $periodMaret2026;

    private BankTransferPostingCoordinator $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OrganizationSeeder::class);

        $this->orgA = Organization::where('code', 'LP-MAARIF-CLP')->firstOrFail();
        $this->orgB = Organization::create([
            'code' => 'ORG-TEST-B',
            'name' => 'Organisasi Test B',
            'type' => 'madrasah',
            'is_active' => true,
        ]);

        $this->seed(CoaSeeder::class);
        $this->seed(MasterDataSeeder::class);

        TenantContext::setTenantId($this->orgA->id);

        $this->maker = User::where('email', 'operator@maarif-cilacap.org')->firstOrFail();
        $this->approver = User::where('email', 'approver@maarif-cilacap.org')->firstOrFail();

        $this->bankGiro308 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%308%')->firstOrFail();

        $this->bankGiro304 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%304%')->firstOrFail();

        $this->bankTab538 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%538%')->firstOrFail();

        $this->cpInternalMaarif = Counterparty::where('organization_id', $this->orgA->id)
            ->where('code', 'INTERNAL-MAARIF')->firstOrFail();

        $this->periodMaret2026 = FiscalPeriod::where('organization_id', $this->orgA->id)
            ->where('code', '2026-03')->firstOrFail();

        $this->coordinator = app(BankTransferPostingCoordinator::class);
    }

    protected function tearDown(): void
    {
        TenantContext::setTenantId(null);
        parent::tearDown();
    }

    private function createTransferTransaction(
        BankAccount $bankAccount,
        string $direction,
        float $amount,
        string $date = '2026-03-15',
        string $desc = 'Transfer Antar Rekening'
    ): BusinessTransaction {
        $bankTx = BankTransaction::create([
            'organization_id' => $bankAccount->organization_id,
            'bank_account_id' => $bankAccount->id,
            'transaction_date' => $date,
            'value_date' => $date,
            'description' => $desc,
            'reference_number' => 'REF-TRF-'.uniqid(),
            'direction' => $direction === 'OUT' ? BankTransactionDirection::OUT : BankTransactionDirection::IN,
            'amount' => $amount,
            'fingerprint' => hash('sha256', uniqid('fp_trf_', true)),
            'balance_after' => 15000000.00,
        ]);

        return BusinessTransaction::create([
            'organization_id' => $bankAccount->organization_id,
            'transaction_number' => BusinessTransaction::generateTransactionNumber($bankAccount->organization_id, 2026),
            'bank_transaction_id' => $bankTx->id,
            'fiscal_period_id' => $this->periodMaret2026->id,
            'transaction_date' => $date,
            'value_date' => $date,
            'transaction_type' => 'BANK_TRANSFER',
            'direction' => $direction,
            'amount' => $amount,
            'counterparty_id' => $this->cpInternalMaarif->id,
            'classification_status' => ClassificationStatus::CLASSIFIED->value,
            'transfer_pairing_status' => TransferPairingStatus::PENDING_PAIRING->value,
            'auto_post_eligible' => true,
            'description' => $desc,
            'status' => 'draft',
        ]);
    }

    public function test_valid_2_to_1_pairing(): void
    {
        $legOut = $this->createTransferTransaction($this->bankGiro308, 'OUT', 5000000.00, '2026-03-15', 'Pindah buku ke Giro 304');
        $legIn = $this->createTransferTransaction($this->bankGiro304, 'IN', 5000000.00, '2026-03-15', 'Terima pindah buku dari Giro 308');

        $result = $this->coordinator->pair($legOut, $legIn, $this->maker);

        $this->assertTrue($result->matched);
        $this->assertSame(TransferPairingStatus::PAIRED, $result->status);
        $this->assertSame($legOut->id, $result->sourceTransactionId);
        $this->assertSame($legIn->id, $result->destinationTransactionId);

        $legOutFresh = $legOut->fresh();
        $legInFresh = $legIn->fresh();

        $this->assertSame(TransferPairingStatus::PAIRED->value, $legOutFresh->transfer_pairing_status);
        $this->assertSame(TransferPairingStatus::PAIRED->value, $legInFresh->transfer_pairing_status);
        $this->assertSame($legIn->id, $legOutFresh->paired_business_transaction_id);
        $this->assertSame($legOut->id, $legInFresh->paired_business_transaction_id);
    }

    public function test_detect_and_pair_deterministic_single_match(): void
    {
        $legOut = $this->createTransferTransaction($this->bankGiro308, 'OUT', 2500000.00, '2026-03-15');
        $legIn = $this->createTransferTransaction($this->bankGiro304, 'IN', 2500000.00, '2026-03-16'); // 1 day difference

        $result = $this->coordinator->detectAndPair($legOut, $this->maker);

        $this->assertTrue($result->matched);
        $this->assertSame(TransferPairingStatus::PAIRED, $result->status);
        $this->assertSame($legOut->id, $result->sourceTransactionId);
        $this->assertSame($legIn->id, $result->destinationTransactionId);

        $this->assertTrue($legOut->fresh()->isTransferPaired());
        $this->assertTrue($legIn->fresh()->isTransferPaired());
    }

    public function test_detect_and_pair_no_match_returns_pending(): void
    {
        $legOut = $this->createTransferTransaction($this->bankGiro308, 'OUT', 7500000.00, '2026-03-15');

        $result = $this->coordinator->detectAndPair($legOut, $this->maker);

        $this->assertFalse($result->matched);
        $this->assertSame(TransferPairingStatus::PENDING_PAIRING, $result->status);
        $this->assertSame('NO_OPPOSITE_LEG_FOUND', $result->reasonCode);
        $this->assertNull($legOut->fresh()->paired_business_transaction_id);
    }

    public function test_detect_and_pair_ambiguous_matches_routed_to_review_queue(): void
    {
        // One OUT transaction
        $legOut = $this->createTransferTransaction($this->bankGiro308, 'OUT', 1000000.00, '2026-03-15', 'Transfer umum tanpa nomor');

        // Two identical IN transactions on same date and amount with generic description
        $candidate1 = $this->createTransferTransaction($this->bankGiro304, 'IN', 1000000.00, '2026-03-15', 'Penerimaan umum tanpa nomor A');
        $candidate2 = $this->createTransferTransaction($this->bankTab538, 'IN', 1000000.00, '2026-03-15', 'Penerimaan umum tanpa nomor B');

        $result = $this->coordinator->detectAndPair($legOut, $this->maker);

        $this->assertFalse($result->matched);
        $this->assertSame(TransferPairingStatus::PAIRING_AMBIGUOUS, $result->status);
        $this->assertSame('PAIRING_AMBIGUOUS', $result->reasonCode);
        $this->assertCount(2, $result->candidateIds);

        $legOutFresh = $legOut->fresh();
        $this->assertSame(TransferPairingStatus::PAIRING_AMBIGUOUS->value, $legOutFresh->transfer_pairing_status);
        $this->assertNull($legOutFresh->paired_business_transaction_id);
        $this->assertNotNull($legOutFresh->posting_error);
        $this->assertSame('PAIRING_AMBIGUOUS', $legOutFresh->posting_error['error_code']);
    }

    public function test_detect_and_pair_tie_breaker_with_account_mention(): void
    {
        // OUT transaction mentions "304" in description
        $legOut = $this->createTransferTransaction($this->bankGiro308, 'OUT', 1000000.00, '2026-03-15', 'Pindah buku ke rek 304');

        // Two identical IN transactions on same date, but one is Giro 304 and one is Tabungan 538
        $candidate304 = $this->createTransferTransaction($this->bankGiro304, 'IN', 1000000.00, '2026-03-15', 'Penerimaan PB');
        $candidate538 = $this->createTransferTransaction($this->bankTab538, 'IN', 1000000.00, '2026-03-15', 'Penerimaan PB');

        $result = $this->coordinator->detectAndPair($legOut, $this->maker);

        $this->assertTrue($result->matched);
        $this->assertSame(TransferPairingStatus::PAIRED, $result->status);
        $this->assertSame($candidate304->id, $result->destinationTransactionId);
        $this->assertSame($candidate304->id, $legOut->fresh()->paired_business_transaction_id);
    }

    public function test_pair_rejects_same_direction(): void
    {
        $legOut1 = $this->createTransferTransaction($this->bankGiro308, 'OUT', 1000000.00);
        $legOut2 = $this->createTransferTransaction($this->bankGiro304, 'OUT', 1000000.00);

        $this->expectException(InvalidTransferPairingException::class);
        $this->expectExceptionMessage('One must be OUT and one must be IN');

        $this->coordinator->pair($legOut1, $legOut2);
    }

    public function test_pair_rejects_same_bank_account(): void
    {
        $legOut = $this->createTransferTransaction($this->bankGiro308, 'OUT', 1000000.00);
        $legIn = $this->createTransferTransaction($this->bankGiro308, 'IN', 1000000.00);

        $this->expectException(InvalidTransferPairingException::class);
        $this->expectExceptionMessage('cannot occur within the same bank account');

        $this->coordinator->pair($legOut, $legIn);
    }

    public function test_pair_rejects_amount_mismatch(): void
    {
        $legOut = $this->createTransferTransaction($this->bankGiro308, 'OUT', 1000000.00);
        $legIn = $this->createTransferTransaction($this->bankGiro304, 'IN', 2000000.00);

        $this->expectException(InvalidTransferPairingException::class);
        $this->expectExceptionMessage('Transfer amounts do not match');

        $this->coordinator->pair($legOut, $legIn);
    }

    public function test_pair_rejects_cross_tenant(): void
    {
        $legOut = $this->createTransferTransaction($this->bankGiro308, 'OUT', 1000000.00);

        TenantContext::setTenantId($this->orgB->id);
        $bankAccountB = BankAccount::where('organization_id', $this->orgB->id)->firstOrFail();
        $legInOrgB = $this->createTransferTransaction($bankAccountB, 'IN', 1000000.00);

        TenantContext::setTenantId($this->orgA->id);

        $this->expectException(CrossTenantViolationException::class);
        $this->coordinator->pair($legOut, $legInOrgB);
    }

    public function test_unpair_resets_status_and_cleans_draft(): void
    {
        $legOut = $this->createTransferTransaction($this->bankGiro308, 'OUT', 3000000.00);
        $legIn = $this->createTransferTransaction($this->bankGiro304, 'IN', 3000000.00);

        $this->coordinator->pair($legOut, $legIn, $this->maker);
        $draft = $this->coordinator->createTransferJournalDraft($legOut, $this->maker);

        $this->assertNotNull($draft);
        $this->assertSame(JournalEntry::STATUS_DRAFT, $draft->status);

        // Execute Unpair
        $unpaired = $this->coordinator->unpair($legOut, $this->maker);
        $this->assertTrue($unpaired);

        $this->assertSame(TransferPairingStatus::PENDING_PAIRING->value, $legOut->fresh()->transfer_pairing_status);
        $this->assertSame(TransferPairingStatus::PENDING_PAIRING->value, $legIn->fresh()->transfer_pairing_status);
        $this->assertNull($legOut->fresh()->paired_business_transaction_id);
        $this->assertNull($legIn->fresh()->paired_business_transaction_id);

        // Draft journal should be removed
        $this->assertNull(JournalEntry::find($draft->id));
    }

    public function test_create_transfer_journal_draft_produces_balanced_pnl_neutral_journal(): void
    {
        $legOut = $this->createTransferTransaction($this->bankGiro308, 'OUT', 4000000.00, '2026-03-15', 'Transfer Giro 308 ke Giro 304');
        $legIn = $this->createTransferTransaction($this->bankGiro304, 'IN', 4000000.00, '2026-03-15', 'Terima Transfer Giro 304');

        $this->coordinator->pair($legOut, $legIn, $this->maker);
        $draft = $this->coordinator->createTransferJournalDraft($legOut, $this->maker);

        $this->assertNotNull($draft);
        $this->assertSame(JournalEntry::STATUS_DRAFT, $draft->status);
        $this->assertSame($this->periodMaret2026->id, $draft->fiscal_period_id);
        $this->assertSame('4000000.00', number_format((float) $draft->lines->sum('debit'), 2, '.', ''));
        $this->assertSame('4000000.00', number_format((float) $draft->lines->sum('credit'), 2, '.', ''));

        // 2:1 Canonical Sources Verification
        $sources = JournalEntrySource::where('journal_entry_id', $draft->id)->get();
        $this->assertCount(2, $sources);

        $sourceLeg = $sources->firstWhere('leg_role', JournalEntrySource::ROLE_TRANSFER_SOURCE);
        $destLeg = $sources->firstWhere('leg_role', JournalEntrySource::ROLE_TRANSFER_DESTINATION);

        $this->assertNotNull($sourceLeg);
        $this->assertNotNull($destLeg);
        $this->assertSame($legOut->id, (int) $sourceLeg->source_id);
        $this->assertSame($legIn->id, (int) $destLeg->source_id);

        // Lines verification: Debit Dest Bank (1130), Credit Source Bank (1120)
        $lines = $draft->lines;
        $this->assertCount(2, $lines);

        $debitLine = $lines->firstWhere('debit', '>', 0);
        $creditLine = $lines->firstWhere('credit', '>', 0);

        $this->assertSame($this->bankGiro304->account_id, $debitLine->account_id);
        $this->assertSame($this->bankGiro308->account_id, $creditLine->account_id);
        $this->assertSame('4000000.00', $debitLine->debit);
        $this->assertSame('4000000.00', $creditLine->credit);

        // Backward compatibility pointer
        $this->assertSame($legOut->id, $draft->business_transaction_id);
        $this->assertSame($draft->id, $legOut->fresh()->posted_journal_entry_id);
        $this->assertSame($draft->id, $legIn->fresh()->posted_journal_entry_id);
    }

    public function test_create_transfer_journal_draft_fails_if_unpaired(): void
    {
        $unpairedOut = $this->createTransferTransaction($this->bankGiro308, 'OUT', 5000000.00);

        $this->expectException(UnpairedTransferException::class);
        $this->coordinator->createTransferJournalDraft($unpairedOut, $this->maker);
    }

    public function test_batch_detect_and_pair(): void
    {
        // Create 2 matching pairs
        $out1 = $this->createTransferTransaction($this->bankGiro308, 'OUT', 1500000.00, '2026-03-10');
        $in1 = $this->createTransferTransaction($this->bankGiro304, 'IN', 1500000.00, '2026-03-10');

        $out2 = $this->createTransferTransaction($this->bankGiro304, 'OUT', 2500000.00, '2026-03-12');
        $in2 = $this->createTransferTransaction($this->bankTab538, 'IN', 2500000.00, '2026-03-12');

        // Create 1 unpaired
        $out3 = $this->createTransferTransaction($this->bankGiro308, 'OUT', 9900000.00, '2026-03-14');

        $stats = $this->coordinator->batchDetectAndPair($this->orgA->id, $this->maker);

        $this->assertSame(5, $stats['total_checked']);
        $this->assertSame(2, $stats['paired_count']);
        $this->assertSame(1, $stats['pending_count']);

        $this->assertTrue($out1->fresh()->isTransferPaired());
        $this->assertTrue($in1->fresh()->isTransferPaired());
        $this->assertTrue($out2->fresh()->isTransferPaired());
        $this->assertTrue($in2->fresh()->isTransferPaired());
        $this->assertFalse($out3->fresh()->isTransferPaired());
    }
}
