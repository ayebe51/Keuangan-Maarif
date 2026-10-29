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
            'direction' => BankTransactionDirection::IN,
            'amount' => '852000.00',
            'description' => 'SETORAN MI DARWATA SINDANGBARANG',
            'fingerprint' => hash('sha256', uniqid('fp_', true)),
            'status' => BankTransactionStatus::UNMATCHED,
            'classification_status' => ClassificationStatus::UNCLASSIFIED,
        ];

        return BankTransaction::create(array_merge($defaults, $overrides));
    }

    /**
     * Test 1: Evaluasi hierarki 7 presedensi berjalan kaku sesuai urutan prioritas (P1 -> P7).
     */
    public function test_01_precedence_hierarchy_evaluated_in_strict_order(): void
    {
        // Setup P1 explicit rule and P4 category rule that could both match
        ClassificationRule::create([
            'organization_id' => $this->orgA->id,
            'name' => 'P1 Explicit Mapping',
            'priority' => 5, // Priority 1 candidate
            'pattern' => 'DARWATA',
            'transaction_type' => BusinessTransactionType::RECEIVABLE_PAYMENT->value,
            'auto_post' => true,
        ]);

        ClassificationRule::create([
            'organization_id' => $this->orgA->id,
            'name' => 'P4 Category Rule',
            'priority' => 100, // Priority 4 candidate
            'pattern' => 'DARWATA',
            'transaction_type' => BusinessTransactionType::DIRECT_INCOME->value,
            'auto_post' => true,
        ]);

        $tx = $this->createBankTransaction(['description' => 'SETORAN MI DARWATA SINDANGBARANG']);
        $match = $this->classificationService->evaluate($tx);

        $this->assertTrue($match->isMatched);
        $this->assertEquals(1, $match->priority);
        $this->assertEquals(ClassificationMethod::EXPLICIT_MAPPING, $match->method);
        $this->assertEquals(BusinessTransactionType::RECEIVABLE_PAYMENT->value, $match->transactionType);
    }

    /**
     * Test 2: Pencocokan Pemetaan Eksplisit Pengguna (P1) menghasilkan auto-post eligible.
     */
    public function test_02_explicit_user_mapping_p1_is_auto_post_eligible(): void
    {
        ClassificationRule::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Explicit Payroll Rule',
            'priority' => 5,
            'rule_type' => 'EXACT_REFERENCE',
            'direction' => 'OUT',
            'pattern' => 'GAJI PEGAWAI MAARIF',
            'transaction_type' => BusinessTransactionType::OPERATING_EXPENSE->value,
            'auto_post' => true,
        ]);

        $tx = $this->createBankTransaction([
            'direction' => BankTransactionDirection::OUT,
            'amount' => '15000000.00',
            'description' => 'GAJI PEGAWAI MAARIF BULAN MARET',
        ]);

        $bizTx = $this->classificationService->classifyTransaction($tx);

        $this->assertEquals(ClassificationStatus::CLASSIFIED, $bizTx->classification_status);
        $this->assertEquals(ClassificationMethod::EXPLICIT_MAPPING->value, $bizTx->classification_method);
        $this->assertTrue($bizTx->auto_post_eligible);
    }

    /**
     * Test 3: Pencocokan Nomor Invoice/Referensi (P2) mencocokkan piutang madrasah secara tepat.
     */
    public function test_03_exact_invoice_reference_match_p2_resolves_receivable(): void
    {
        $period = FiscalPeriod::where('organization_id', $this->orgA->id)->firstOrFail();
        $recId = DB::table('receivables')->insertGetId([
            'organization_id' => $this->orgA->id,
            'fiscal_period_id' => $period->id,
            'counterparty_id' => $this->cpDarwata->id,
            'receivable_number' => 'RCV-2026-00088',
            'receivable_type' => 'school_fee',
            'due_date' => '2026-03-31',
            'issue_date' => '2026-03-01',
            'original_amount' => 852000.00,
            'outstanding_amount' => 852000.00,
            'status' => 'outstanding',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tx = $this->createBankTransaction([
            'description' => 'PEMBAYARAN RCV-2026-00088 MI DARWATA',
            'counterparty_id' => $this->cpDarwata->id,
            'amount' => '852000.00',
        ]);

        $bizTx = $this->classificationService->classifyTransaction($tx);

        $this->assertEquals(ClassificationStatus::CLASSIFIED, $bizTx->classification_status);
        $this->assertEquals(ClassificationMethod::INVOICE_REFERENCE->value, $bizTx->classification_method);
        $this->assertEquals(BusinessTransactionType::RECEIVABLE_PAYMENT->value, $bizTx->transaction_type);
        $this->assertTrue($bizTx->auto_post_eligible);
    }

    /**
     * Test 4: Pencocokan Kode Warisan (P3) mengekstrak kode legacy (misal 1314) dan mengarahkan ke akun kanonikal.
     */
    public function test_04_legacy_code_mapping_p3_extracts_and_maps_to_canonical_coa(): void
    {
        $coaAcc = Account::where('organization_id', $this->orgA->id)
            ->where('code', '1210')
            ->firstOrFail();

        AccountMapping::create([
            'organization_id' => $this->orgA->id,
            'account_id' => $coaAcc->id,
            'mapping_type' => AccountMapping::TYPE_LEGACY_CODE,
            'mapping_key' => '1314',
            'is_active' => true,
        ]);

        $tx = $this->createBankTransaction([
            'description' => 'SETORAN IURAN KODE [1314] SINDANGBARANG',
        ]);

        $bizTx = $this->classificationService->classifyTransaction($tx);

        $this->assertEquals(ClassificationStatus::CLASSIFIED, $bizTx->classification_status);
        $this->assertEquals(ClassificationMethod::LEGACY_CODE->value, $bizTx->classification_method);
        $this->assertEquals((int) $coaAcc->id, (int) $bizTx->target_account_id);
        $this->assertEquals(BusinessTransactionType::RECEIVABLE_PAYMENT->value, $bizTx->transaction_type);
        $this->assertTrue($bizTx->auto_post_eligible);
    }

    /**
     * Test 5: Aturan Kategori Deterministik (P4) mengklasifikasikan bunga bank dan biaya admin bank.
     */
    public function test_05_deterministic_category_rules_p4_classify_interest_and_tax(): void
    {
        $accInterest = Account::where('organization_id', $this->orgA->id)->where('code', '4200')->firstOrFail();
        $accFee = Account::where('organization_id', $this->orgA->id)->where('code', '5200')->firstOrFail();

        ClassificationRule::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Bunga Bank BRI',
            'priority' => 100,
            'direction' => 'IN',
            'pattern' => 'BUNGA',
            'transaction_type' => BusinessTransactionType::BANK_INTEREST->value,
            'target_account_id' => $accInterest->id,
            'counterparty_id' => $this->cpBri->id,
            'auto_post' => true,
        ]);

        ClassificationRule::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Biaya Adm & Pajak Bank BRI',
            'priority' => 100,
            'direction' => 'OUT',
            'pattern' => 'PAJAK',
            'transaction_type' => BusinessTransactionType::BANK_FEE_TAX->value,
            'target_account_id' => $accFee->id,
            'counterparty_id' => $this->cpBri->id,
            'auto_post' => true,
        ]);

        // 1. Bank Interest (Credit = IN)
        $txIn = $this->createBankTransaction([
            'direction' => BankTransactionDirection::IN,
            'amount' => '125000.00',
            'description' => 'BUNGA REKENING BULAN MARET',
        ]);
        $bizIn = $this->classificationService->classifyTransaction($txIn);

        $this->assertEquals(BusinessTransactionType::BANK_INTEREST->value, $bizIn->transaction_type);
        $this->assertEquals(ClassificationStatus::CLASSIFIED, $bizIn->classification_status);
        $this->assertEquals($accInterest->id, $bizIn->target_account_id);
        $this->assertTrue($bizIn->auto_post_eligible);

        // 2. Bank Tax / Admin (Debit = OUT)
        $txOut = $this->createBankTransaction([
            'direction' => BankTransactionDirection::OUT,
            'amount' => '25000.00',
            'description' => 'PAJAK BULANAN REKENING',
        ]);
        $bizOut = $this->classificationService->classifyTransaction($txOut);

        $this->assertEquals(BusinessTransactionType::BANK_FEE_TAX->value, $bizOut->transaction_type);
        $this->assertEquals(ClassificationStatus::CLASSIFIED, $bizOut->classification_status);
        $this->assertEquals($accFee->id, $bizOut->target_account_id);
        $this->assertTrue($bizOut->auto_post_eligible);
    }

    /**
     * Test 6: Golden Gate Kasus MI Darwata Sindangbarang: Kredit Rp852.000 teridentifikasi direction=IN, role=PAYER.
     */
    public function test_06_golden_gate_mi_darwata_sindangbarang_credit_852000(): void
    {
        $period = FiscalPeriod::where('organization_id', $this->orgA->id)->firstOrFail();
        DB::table('receivables')->insert([
            'organization_id' => $this->orgA->id,
            'fiscal_period_id' => $period->id,
            'counterparty_id' => $this->cpDarwata->id,
            'receivable_number' => 'RCV-2026-DARWATA',
            'receivable_type' => 'school_fee',
            'due_date' => '2026-03-31',
            'issue_date' => '2026-03-01',
            'original_amount' => 852000.00,
            'outstanding_amount' => 852000.00,
            'status' => 'outstanding',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tx = $this->createBankTransaction([
            'bank_account_id' => $this->account538->id,
            'direction' => BankTransactionDirection::IN,
            'amount' => '852000.00',
            'description' => 'SETORAN DARI MI DARWATA SINDANGBARANG',
            'counterparty_id' => $this->cpDarwata->id,
        ]);

        $bizTx = $this->classificationService->classifyTransaction($tx);

        $this->assertEquals('IN', $bizTx->direction);
        $this->assertEquals('852000.00', $bizTx->amount);
        $this->assertEquals($this->cpDarwata->id, $bizTx->counterparty_id);
        $this->assertEquals(Counterparty::ROLE_SCHOOL, $this->cpDarwata->role);
        $this->assertEquals(BusinessTransactionType::RECEIVABLE_PAYMENT->value, $bizTx->transaction_type);
        $this->assertEquals(ClassificationStatus::CLASSIFIED, $bizTx->classification_status);
        $this->assertTrue($bizTx->auto_post_eligible);
    }

    /**
     * Test 7: Pencocokan Pola Historis Rekanan (P5) menghasilkan saran (SUGGESTED), bukan auto-post.
     */
    public function test_07_historical_heuristic_p5_yields_suggestion_only_no_autopost(): void
    {
        $period = FiscalPeriod::where('organization_id', $this->orgA->id)->firstOrFail();
        $targetAcc = Account::where('organization_id', $this->orgA->id)->where('code', '5100')->firstOrFail();

        // Seed 2 historical approved transactions
        for ($i = 1; $i <= 2; $i++) {
            BusinessTransaction::create([
                'organization_id' => $this->orgA->id,
                'transaction_number' => "BT-HIST-00{$i}",
                'fiscal_period_id' => $period->id,
                'transaction_date' => '2026-02-15',
                'transaction_type' => BusinessTransactionType::OPERATING_EXPENSE->value,
                'direction' => 'OUT',
                'amount' => '500000.00',
                'counterparty_id' => $this->cpDarwata->id,
                'target_account_id' => $targetAcc->id,
                'status' => 'approved',
                'classification_status' => ClassificationStatus::APPROVED,
                'description' => 'Honor Pengawas',
            ]);
        }

        $tx = $this->createBankTransaction([
            'direction' => BankTransactionDirection::OUT,
            'amount' => '500000.00',
            'counterparty_id' => $this->cpDarwata->id,
            'description' => 'TRANSAKSI REKANAN TANPA ATURAN DETERMINISTIK',
        ]);

        $bizTx = $this->classificationService->classifyTransaction($tx);

        $this->assertEquals(ClassificationStatus::SUGGESTED, $bizTx->classification_status);
        $this->assertEquals(ClassificationMethod::HISTORICAL_RULE->value, $bizTx->classification_method);
        $this->assertFalse($bizTx->auto_post_eligible); // MUST BE FALSE for Suggestion
        $this->assertGreaterThanOrEqual(0.70, $bizTx->classification_confidence);
    }

    /**
     * Test 8: Pagar Pengaman Saran AI (P6) menghasilkan confidence & evidence, mutlak tidak auto-post.
     */
    public function test_08_ai_suggestion_p6_invariant_never_autoposts(): void
    {
        $tx = $this->createBankTransaction([
            'direction' => BankTransactionDirection::OUT,
            'amount' => '150000.00',
            'description' => 'PEMBELIAN KONSUMSI DAN SNACK RAPAT PLENO',
            'counterparty_id' => null,
        ]);

        $bizTx = $this->classificationService->classifyTransaction($tx);

        $this->assertEquals(ClassificationStatus::SUGGESTED, $bizTx->classification_status);
        $this->assertEquals(ClassificationMethod::AI_SUGGESTION->value, $bizTx->classification_method);
        $this->assertFalse($bizTx->auto_post_eligible); // Critical Invariant REV3-10
        $this->assertGreaterThan(0.70, $bizTx->classification_confidence);

        // Verify suggestion was stored in classification_suggestions
        $sug = ClassificationSuggestion::where('bank_transaction_id', $tx->id)->first();
        $this->assertNotNull($sug);
        $this->assertEquals(ClassificationSuggestion::STATUS_PENDING, $sug->status);
        $this->assertNotEmpty($sug->evidence);
    }

    /**
     * Test 9: Fallback Unclassified (P7) masuk antrean Review Queue dengan alasan transparan.
     */
    public function test_09_unclassified_fallback_p7_routes_to_review_queue(): void
    {
        $tx = $this->createBankTransaction([
            'direction' => BankTransactionDirection::IN,
            'amount' => '999999.00',
            'description' => 'ZZZZ RANDOM STRING NO KEYWORD 12345XYZ',
            'counterparty_id' => null,
        ]);

        $bizTx = $this->classificationService->classifyTransaction($tx);

        $this->assertEquals(ClassificationStatus::UNCLASSIFIED, $bizTx->classification_status);
        $this->assertFalse($bizTx->auto_post_eligible);

        // Test API queue endpoint
        Sanctum::actingAs($this->adminA);
        $res = $this->getJson('/api/v1/transactions/unclassified', ['X-Tenant-Id' => $this->orgA->id]);
        $res->assertStatus(200);
        $res->assertJsonFragment(['id' => $tx->id]);
    }

    /**
     * Test 10: Deteksi & Pemasangan 2-Leg Transfer Bank Internal (BANK_TRANSFER P&L-Neutral).
     */
    public function test_10_internal_bank_transfer_detection_and_pairing(): void
    {
        // 1. Source Account 308 (OUT Rp 10.000.000)
        $txOut = $this->createBankTransaction([
            'bank_account_id' => $this->account308->id,
            'direction' => BankTransactionDirection::OUT,
            'amount' => '10000000.00',
            'transaction_date' => '2026-03-05',
            'description' => 'TRF KE REK BRI 538 TABUNGAN LP MAARIF',
        ]);

        // 2. Destination Account 538 (IN Rp 10.000.000)
        $txIn = $this->createBankTransaction([
            'bank_account_id' => $this->account538->id,
            'direction' => BankTransactionDirection::IN,
            'amount' => '10000000.00',
            'transaction_date' => '2026-03-05',
            'description' => 'TRF DARI REK 308 GIRO LP MAARIF',
        ]);

        $bizOut = $this->classificationService->classifyTransaction($txOut);
        $bizIn = $this->classificationService->classifyTransaction($txIn);

        $this->assertEquals(BusinessTransactionType::BANK_TRANSFER->value, $bizOut->transaction_type);
        $this->assertEquals(BusinessTransactionType::BANK_TRANSFER->value, $bizIn->transaction_type);
        $this->assertTrue($bizOut->auto_post_eligible);
        $this->assertTrue($bizIn->auto_post_eligible);
    }

    /**
     * Test 11: Proteksi Periode Fiskal Tertutup (Memblokir auto-post untuk transaksi di periode CLOSED).
     */
    public function test_11_closed_fiscal_period_protection_blocks_autopost(): void
    {
        // Create closed period
        $closedPeriod = FiscalPeriod::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Januari 2026',
            'code' => '2026-01',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => FiscalPeriod::STATUS_CLOSED,
        ]);

        // Create rule that would otherwise auto-post
        ClassificationRule::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Bunga Bank Rule',
            'priority' => 10,
            'direction' => 'IN',
            'pattern' => 'BUNGA',
            'transaction_type' => BusinessTransactionType::BANK_INTEREST->value,
            'auto_post' => true,
        ]);

        $tx = $this->createBankTransaction([
            'transaction_date' => '2026-01-15',
            'direction' => BankTransactionDirection::IN,
            'amount' => '50000.00',
            'description' => 'BUNGA REKENING JANUARI',
        ]);

