<?php

namespace Tests\Feature;

use App\Domain\Accounting\DTOs\AutomationPostingAuthorization;
use App\Domain\Accounting\Enums\AutoPostDecisionOutcome;
use App\Domain\Accounting\Enums\TransferPairingStatus;
use App\Domain\Accounting\Exceptions\AlreadyReversedException;
use App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domain\Accounting\Exceptions\InactiveAccountException;
use App\Domain\Accounting\Exceptions\NonPostableAccountException;
use App\Domain\Accounting\Exceptions\SegregationOfDutiesException;
use App\Domain\Accounting\Exceptions\TransactionMakerCannotApproveRuleException;
use App\Domain\Accounting\Exceptions\UnbalancedJournalException;
use App\Domain\Accounting\Exceptions\UnpairedTransferException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntrySource;
use App\Domain\Accounting\Services\AccountMappingResolver;
use App\Domain\Accounting\Services\AutoPostExecutionCoordinator;
use App\Domain\Accounting\Services\BankTransferPostingCoordinator;
use App\Domain\Accounting\Services\BusinessTransactionPostingCoordinator;
use App\Domain\Accounting\Services\FiscalPeriodService;
use App\Domain\Accounting\Services\JournalDraftGenerator;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingMatrixResolver;
use App\Domain\Accounting\Services\UnappliedCashHandler;
use App\Domain\Bank\Enums\BankTransactionDirection;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Models\BankImport;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Classification\Models\ClassificationRule;
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

class GoldenGateTestSuiteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $admin;

    private User $operator;

    private User $approver;

    private User $plainUser;

    private FiscalPeriod $periodOpen;

    private Account $accKas1110;

    private Account $accBankGiro1120;

    private Account $accBankGiro1130;

    private Account $accUnapplied2200;

    private Account $accPendapatan4100;

    private Account $accBeban5000;

    private Account $accBebanAdm5200;

    private Account $accBebanPajak5300;

    private BankAccount $bankBri308;

    private BankAccount $bankBri304;

    private BankAccount $bankOrgB;

    private Counterparty $counterpartyMaarif;

    private Counterparty $counterpartyOrgB;

    private JournalPostingService $journalPostingService;

    private BusinessTransactionPostingCoordinator $postingCoordinator;

    private AutoPostExecutionCoordinator $autoPostCoordinator;

    private BankTransferPostingCoordinator $transferCoordinator;

    private UnappliedCashHandler $unappliedCashHandler;

    private JournalDraftGenerator $draftGenerator;

    private FiscalPeriodService $fiscalPeriodService;

    private PostingMatrixResolver $postingMatrixResolver;

    private AccountMappingResolver $accountMappingResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OrganizationSeeder::class);
        $this->seed(CoaSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $this->orgA = Organization::where('code', 'LP-MAARIF-CLP')->firstOrFail();

        // Fixtures for second tenant Org B with scoping bypassed
        TenantContext::setBypassScoping(true);
        $this->orgB = Organization::create([
            'code' => 'ORG-TEST-B',
            'name' => 'Organisasi Test B',
            'type' => 'madrasah',
            'is_active' => true,
        ]);

        $cashB = Account::create([
            'organization_id' => $this->orgB->id,
            'code' => '1110-B',
            'name' => 'Kas Org B',
            'account_type' => Account::TYPE_ASSET,
            'normal_balance' => Account::BALANCE_DEBIT,
            'is_postable' => true,
            'is_active' => true,
        ]);

        $this->bankOrgB = BankAccount::create([
            'organization_id' => $this->orgB->id,
            'bank_name' => 'BRI Cabang B',
            'account_number' => '8899001122',
            'account_name' => 'Rekening Org B',
            'branch' => 'Cilacap',
            'type' => 'giro',
            'account_id' => $cashB->id,
            'is_active' => true,
        ]);

        $this->counterpartyOrgB = Counterparty::create([
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
        $this->approver->syncPermissions(['journal.view', 'journal.review', 'journal.approve', 'journal.post', 'journal.reverse']);

        $this->plainUser = User::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Plain User Without Permissions',
            'email' => 'plain.user@maarif.test',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->periodOpen = FiscalPeriod::where('organization_id', $this->orgA->id)
            ->where('code', '2026-03')
            ->firstOrFail();

        $this->accKas1110 = Account::where('organization_id', $this->orgA->id)->where('code', '1110')->firstOrFail();
        $this->accBankGiro1120 = Account::where('organization_id', $this->orgA->id)->where('code', '1120')->firstOrFail();
        $this->accBankGiro1130 = Account::where('organization_id', $this->orgA->id)->where('code', '1130')->firstOrFail();
        $this->accUnapplied2200 = Account::where('organization_id', $this->orgA->id)->where('code', '2200')->firstOrFail();
        $this->accPendapatan4100 = Account::where('organization_id', $this->orgA->id)->where('code', '4100')->firstOrFail();

        // Operational & Bank expense accounts
        $this->accBeban5000 = Account::where('organization_id', $this->orgA->id)->where('code', '5000')->firstOrFail();

        $this->accBebanAdm5200 = Account::firstOrCreate(
            ['organization_id' => $this->orgA->id, 'code' => '5200'],
            [
                'parent_id' => $this->accBeban5000->id,
                'name' => 'Beban Administrasi Bank',
                'account_type' => Account::TYPE_EXPENSE,
                'normal_balance' => Account::BALANCE_DEBIT,
                'is_postable' => true,
                'is_active' => true,
            ]
        );

        $this->accBebanPajak5300 = Account::firstOrCreate(
            ['organization_id' => $this->orgA->id, 'code' => '5300'],
            [
                'parent_id' => $this->accBeban5000->id,
                'name' => 'Beban Pajak Bunga Bank',
                'account_type' => Account::TYPE_EXPENSE,
                'normal_balance' => Account::BALANCE_DEBIT,
                'is_postable' => true,
                'is_active' => true,
            ]
        );

        $this->bankBri308 = BankAccount::where('organization_id', $this->orgA->id)->where('account_number', 'LIKE', '%308%')->firstOrFail();
        $this->bankBri304 = BankAccount::where('organization_id', $this->orgA->id)->where('account_number', 'LIKE', '%304%')->firstOrFail();
        $this->counterpartyMaarif = Counterparty::where('organization_id', $this->orgA->id)->firstOrFail();

        $this->journalPostingService = app(JournalPostingService::class);
        $this->postingCoordinator = app(BusinessTransactionPostingCoordinator::class);
        $this->autoPostCoordinator = app(AutoPostExecutionCoordinator::class);
        $this->transferCoordinator = app(BankTransferPostingCoordinator::class);
        $this->unappliedCashHandler = app(UnappliedCashHandler::class);
        $this->draftGenerator = app(JournalDraftGenerator::class);
        $this->fiscalPeriodService = app(FiscalPeriodService::class);
        $this->postingMatrixResolver = app(PostingMatrixResolver::class);
        $this->accountMappingResolver = app(AccountMappingResolver::class);
    }

    protected function tearDown(): void
    {
        TenantContext::setTenantId(null);
        parent::tearDown();
    }

    // =========================================================================
    // GOLDEN SCENARIOS (1 to 10)
    // =========================================================================

    public function test_golden_scenario_01_direct_income_end_to_end(): void
    {
        // 1. Raw bank transaction ingestion
        $import = BankImport::create([
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $this->bankBri308->id,
            'filename' => 'statement_march.csv',
            'format' => 'csv_bri_giro',
            'imported_by' => $this->operator->id,
            'imported_at' => now(),
            'total_rows' => 1,
            'status' => 'done',
        ]);

        $bankTx = BankTransaction::create([
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $this->bankBri308->id,
            'bank_import_id' => $import->id,
            'transaction_date' => '2026-03-10',
            'value_date' => '2026-03-10',
            'amount' => 500000.00,
            'direction' => BankTransactionDirection::IN,
            'description' => 'INFAQ OPERASIONAL MADRASAH',
            'reference_number' => 'REF-INC-001',
            'fingerprint' => hash('sha256', 'REF-INC-001'),
            'balance_after' => 10500000.00,
        ]);

        // 2. Business transaction creation
        $tx = BusinessTransaction::create([
            'organization_id' => $this->orgA->id,
            'bank_transaction_id' => $bankTx->id,
            'transaction_number' => 'BTX-INC-001',
            'transaction_date' => '2026-03-10',
            'transaction_type' => 'DIRECT_INCOME',
            'direction' => 'IN',
            'amount' => '500000.00',
            'description' => 'INFAQ OPERASIONAL MADRASAH',
            'status' => 'pending',
            'is_period_locked' => false,
            'fiscal_period_id' => $this->periodOpen->id,
            'counterparty_id' => $this->counterpartyMaarif->id,
        ]);

        // 3. Draft generation via Posting Matrix
        $draft = $this->draftGenerator->generate(
            transaction: $tx,
            maker: $this->operator,
            options: ['bank_account' => $this->bankBri308]
        );

        $this->assertEquals(JournalEntry::STATUS_DRAFT, $draft->status);
        $this->assertEquals(2, $draft->lines()->count());

        // Line 1: Debit Bank BRI 308 (1120)
        $line1 = $draft->lines()->where('line_number', 1)->firstOrFail();
        $this->assertEquals($this->accBankGiro1120->id, $line1->account_id);
        $this->assertEquals('500000.00', $line1->debit);
        $this->assertEquals('0.00', $line1->credit);

        // Line 2: Credit Pendapatan Infaq (4100)
        $line2 = $draft->lines()->where('line_number', 2)->firstOrFail();
        $this->assertEquals($this->accPendapatan4100->id, $line2->account_id);
        $this->assertEquals('0.00', $line2->debit);
        $this->assertEquals('500000.00', $line2->credit);

        // 4. Provenance source verification
        $source = JournalEntrySource::where('journal_entry_id', $draft->id)->firstOrFail();
        $this->assertEquals($tx->id, $source->source_id);
        $this->assertEquals(JournalEntrySource::ROLE_PRIMARY, $source->leg_role);

        // 5. Human approval and posting
        $result = $this->postingCoordinator->approveAndPost($tx, $this->approver);
        $this->assertTrue($result['success']);
        $this->assertFalse($result['is_duplicate']);

        $draft->refresh();
        $this->assertEquals(JournalEntry::STATUS_POSTED, $draft->status);
        $this->assertEquals($this->approver->id, $draft->posted_by);

        $tx->refresh();
        $this->assertEquals('posted', $tx->status);
        $this->assertEquals($draft->id, $tx->posted_journal_entry_id);
    }

    public function test_golden_scenario_02_operating_expense_end_to_end(): void
    {
        $tx = $this->createSimpleTransaction('OPERATING_EXPENSE', 'OUT', '350000.00', $this->bankBri308->id);

        $draft = $this->draftGenerator->generate(
            transaction: $tx,
            maker: $this->approver,
            options: ['bank_account' => $this->bankBri308]
        );

        // Debit: Beban Operasional / Kas; Credit: Bank BRI 308
        $debitLine = $draft->lines()->where('debit', '>', 0)->firstOrFail();
        $creditLine = $draft->lines()->where('credit', '>', 0)->firstOrFail();

        $this->assertEquals('350000.00', $debitLine->debit);
        $this->assertEquals('350000.00', $creditLine->credit);
        $this->assertEquals($this->accBankGiro1120->id, $creditLine->account_id);

        // Maker cannot approve own transaction (Segregation of Duties)
        $this->expectException(SegregationOfDutiesException::class);
        $this->postingCoordinator->approveAndPost($tx, $this->approver);
    }

    public function test_golden_scenario_03_bank_fee_and_tax_compound_entry(): void
    {
        $entryDate = $this->periodOpen->start_date->format('Y-m-d');

        // Multi-line compound draft: Beban Adm Bank + Beban Pajak Bunga vs Bank BRI Giro
        $draft = $this->journalPostingService->createDraft([
            'organization_id' => $this->orgA->id,
            'entry_date' => $entryDate,
            'description' => 'Compound Bank Fee and Tax Monthly Posting',
            'lines' => [
                ['account_id' => $this->accBebanAdm5200->id, 'debit' => '25000.00', 'credit' => '0.00'],
                ['account_id' => $this->accBebanPajak5300->id, 'debit' => '15000.00', 'credit' => '0.00'],
                ['account_id' => $this->accBankGiro1120->id, 'debit' => '0.00', 'credit' => '40000.00'],
            ],
        ], $this->operator);

        $this->assertEquals(3, $draft->lines()->count());

        $posted = $this->journalPostingService->post($draft, $this->approver);

        $this->assertEquals(JournalEntry::STATUS_POSTED, $posted->status);
        $totalDebit = $posted->lines()->sum('debit');
        $totalCredit = $posted->lines()->sum('credit');
        $this->assertEquals(40000.0, (float) $totalDebit);
        $this->assertEquals(40000.0, (float) $totalCredit);
    }

    public function test_golden_scenario_04_internal_bank_transfer_2_to_1_cardinality(): void
    {
        $txOut = $this->createSimpleTransaction('BANK_TRANSFER', 'OUT', '2000000.00', $this->bankBri308->id);
        $txIn = $this->createSimpleTransaction('BANK_TRANSFER', 'IN', '2000000.00', $this->bankBri304->id);

        // 1. Pair transactions
        $pairResult = $this->transferCoordinator->pair($txOut, $txIn, $this->approver);
        $this->assertTrue($pairResult->isSuccessful());

        $txOut->refresh();
        $txIn->refresh();
        $this->assertEquals(TransferPairingStatus::PAIRED->value, $txOut->transfer_pairing_status);
        $this->assertEquals(TransferPairingStatus::PAIRED->value, $txIn->transfer_pairing_status);
        $this->assertEquals($txIn->id, $txOut->paired_business_transaction_id);
        $this->assertEquals($txOut->id, $txIn->paired_business_transaction_id);

        // 2. Generate 2:1 Compound Draft
        $draft = $this->transferCoordinator->createTransferJournalDraft($txOut, $this->operator);

        $this->assertEquals(2, $draft->lines()->count());
