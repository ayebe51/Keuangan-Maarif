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
        $lineDebit = $draft->lines()->where('debit', '>', 0)->firstOrFail();
        $lineCredit = $draft->lines()->where('credit', '>', 0)->firstOrFail();

        // Debit destination (Bank BRI 304), Credit source (Bank BRI 308)
        $this->assertEquals($this->accBankGiro1130->id, $lineDebit->account_id);
        $this->assertEquals('2000000.00', $lineDebit->debit);
        $this->assertEquals($this->accBankGiro1120->id, $lineCredit->account_id);
        $this->assertEquals('2000000.00', $lineCredit->credit);

        // 3. Verify exactly 2 provenance links
        $sources = JournalEntrySource::where('journal_entry_id', $draft->id)->get();
        $this->assertCount(2, $sources);
        $this->assertTrue($sources->contains('leg_role', 'transfer_source'));
        $this->assertTrue($sources->contains('leg_role', 'transfer_destination'));

        // 4. Post and verify twin leg synchronization
        $postResult = $this->postingCoordinator->approveAndPost($txOut, $this->approver);
        $this->assertTrue($postResult['success']);

        $txOut->refresh();
        $txIn->refresh();
        $this->assertEquals('posted', $txOut->status);
        $this->assertEquals('posted', $txIn->status);
        $this->assertEquals($draft->id, $txOut->posted_journal_entry_id);
        $this->assertEquals($draft->id, $txIn->posted_journal_entry_id);

        // 5. P&L neutrality: Only Asset accounts involved
        foreach ($draft->lines as $line) {
            $this->assertEquals(Account::TYPE_ASSET, $line->account->account_type);
        }

        // 6. Reversal synchronizes both twin legs
        $reversal = $this->postingCoordinator->reverse($txOut, $this->approver, 'Koreksi transfer internal');
        $this->assertTrue($reversal['success']);

        $txOut->refresh();
        $txIn->refresh();
        $this->assertEquals('reversed', $txOut->status);
        $this->assertEquals('reversed', $txIn->status);
    }

    public function test_golden_scenario_05_ambiguous_bank_transfer_prevented(): void
    {
        $txOut = $this->createSimpleTransaction('BANK_TRANSFER', 'OUT', '3000000.00', $this->bankBri308->id);

        // Two identical candidate inward transactions
        $txIn1 = $this->createSimpleTransaction('BANK_TRANSFER', 'IN', '3000000.00', $this->bankBri304->id);
        $txIn2 = $this->createSimpleTransaction('BANK_TRANSFER', 'IN', '3000000.00', $this->bankBri304->id);

        // Automatic detection must return AMBIGUOUS without guessing
        $result = $this->transferCoordinator->detectAndPair($txOut);

        $this->assertEquals(TransferPairingStatus::PAIRING_AMBIGUOUS, $result->status);
        $this->assertFalse($result->isSuccessful());

        // Posting an unpaired or ambiguous transfer is strictly blocked
        $this->expectException(UnpairedTransferException::class);
        $this->postingCoordinator->approveAndPost($txOut, $this->approver);
    }

    public function test_golden_scenario_06_unapplied_cash_liability_to_recognition(): void
    {
        $tx = $this->createSimpleTransaction('UNAPPLIED_CASH', 'IN', '1250000.00', $this->bankBri308->id);

        // 1. Initial draft maps to liability 2200 (Titipan Kas)
        $draft = $this->unappliedCashHandler->createDraft($tx, $this->operator);

        $lineCredit = $draft->lines()->where('credit', '>', 0)->firstOrFail();
        $this->assertEquals($this->accUnapplied2200->id, $lineCredit->account_id);
        $this->assertEquals('1250000.00', $lineCredit->credit);

        // P&L neutrality: liability account is Equity/Liability, NOT Revenue
        $this->assertEquals(Account::TYPE_LIABILITY, $lineCredit->account->account_type);

        // 2. Human reviewer resolves once donor is identified
        $resolvedTx = $this->unappliedCashHandler->resolve(
            transaction: $tx,
            resolutionData: [
                'target_account_id' => $this->accPendapatan4100->id,
                'transaction_type' => 'DIRECT_INCOME',
                'counterparty_id' => $this->counterpartyMaarif->id,
            ],
            resolver: $this->approver
        );

        $this->assertEquals('DIRECT_INCOME', $resolvedTx->transaction_type);
        $this->assertEquals($this->accPendapatan4100->id, $resolvedTx->target_account_id);

        // 3. Regenerate draft with definitive revenue account
        $newDraft = $this->draftGenerator->generate($resolvedTx, $this->operator, ['bank_account' => $this->bankBri308]);
        $newCredit = $newDraft->lines()->where('credit', '>', 0)->firstOrFail();
        $this->assertEquals($this->accPendapatan4100->id, $newCredit->account_id);

        // 4. Post to ledger
        $postRes = $this->postingCoordinator->approveAndPost($resolvedTx, $this->approver);
        $this->assertTrue($postRes['success']);
    }

    public function test_golden_scenario_07_human_approval_path_idempotency(): void
    {
        $tx = $this->createSimpleTransaction('DIRECT_INCOME', 'IN', '800000.00', $this->bankBri308->id);

        $this->draftGenerator->generate($tx, $this->operator, ['bank_account' => $this->bankBri308]);

        // First approval
        $res1 = $this->postingCoordinator->approveAndPost($tx, $this->approver);
        $this->assertTrue($res1['success']);
        $this->assertFalse($res1['is_duplicate']);

        // Idempotent second approval: returns success without creating second journal
        $res2 = $this->postingCoordinator->approveAndPost($tx, $this->approver);
        $this->assertTrue($res2['success']);
        $this->assertTrue($res2['is_duplicate']);
        $this->assertEquals($res1['data']['journal_entry_id'], $res2['data']['journal_entry_id']);

        // Verify only 1 journal entry exists
        $this->assertEquals(1, JournalEntry::where('business_transaction_id', $tx->id)->count());
    }

    public function test_golden_scenario_08_automated_posting_path_with_zero_identity_spoofing(): void
    {
        // 1. Create an active approved rule
        $rule = ClassificationRule::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Auto-Post Rutin Infaq',
            'priority' => 10,
            'is_active' => true,
            'rule_type' => 'KEYWORD',
            'direction' => 'IN',
            'bank_account_id' => $this->bankBri308->id,
            'pattern' => 'INFAQ RUTIN',
            'pattern_type' => 'contains',
            'transaction_type' => 'DIRECT_INCOME',
            'target_account_id' => $this->accPendapatan4100->id,
            'auto_post' => true,
            'max_auto_post_amount' => '5000000.00',
            'version' => 1,
            'created_by' => $this->operator->id,
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]);

        $tx = $this->createSimpleTransaction('DIRECT_INCOME', 'IN', '750000.00', $this->bankBri308->id);
        $tx->rule_snapshot = $rule->toSnapshot();
        $tx->status = 'classified';
        $tx->classification_status = 'classified';
        $tx->classification_method = 'DETERMINISTIC_RULE';
        $tx->auto_post_eligible = true;
        $tx->target_account_id = $this->accPendapatan4100->id;
        $tx->save();

        $result = $this->autoPostCoordinator->execute($tx->id);

        $this->assertTrue($result['success']);
        $this->assertEquals(AutoPostDecisionOutcome::ELIGIBLE->value, $result['outcome']);

        $tx->refresh();
        $this->assertEquals('posted', $tx->status);

        $postedJournal = JournalEntry::findOrFail($tx->posted_journal_entry_id);
        $this->assertEquals(JournalEntry::STATUS_POSTED, $postedJournal->status);

        // ZERO IDENTITY SPOOFING: posted_by must be NULL, posted_by_rule_id set
        $this->assertNull($postedJournal->posted_by);
        $this->assertEquals($rule->id, $postedJournal->posted_by_rule_id);
    }

    public function test_golden_scenario_09_immutable_ledger_and_historical_reversal(): void
    {
        $tx = $this->createSimpleTransaction('DIRECT_INCOME', 'IN', '600000.00', $this->bankBri308->id);
        $this->draftGenerator->generate($tx, $this->operator, ['bank_account' => $this->bankBri308]);
        $this->postingCoordinator->approveAndPost($tx, $this->approver);

        $tx->refresh();
        $origJournal = $tx->postedJournalEntry;
        $this->assertEquals(JournalEntry::STATUS_POSTED, $origJournal->status);

        // Next period
        $nextPeriod = FiscalPeriod::create([
            'organization_id' => $this->orgA->id,
            'code' => 'PER-NEXT-2026',
            'name' => 'Next Fiscal Period 2026',
            'period_type' => 'monthly',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
            'status' => FiscalPeriod::STATUS_OPEN,
            'is_current' => false,
        ]);

        // Close original period
        $this->fiscalPeriodService->closePeriod($this->periodOpen, $this->admin);

        // Reversal into open next period
        $reversal = $this->journalPostingService->reverse(
            originalEntry: $origJournal,
            user: $this->approver,
            reason: 'Koreksi audit periode lalu',
            reversalDate: '2026-04-05'
        );

        $this->assertEquals(JournalEntry::STATUS_POSTED, $reversal->status);
        $this->assertEquals($nextPeriod->id, $reversal->fiscal_period_id);

        // Original entry remains immutable in locked period, marked reversed
        $origJournal->refresh();
        $this->assertEquals(JournalEntry::STATUS_REVERSED, $origJournal->status);
        $this->assertEquals($reversal->id, $origJournal->reversed_entry_id);

        // Double reversal is strictly prevented
        $this->expectException(AlreadyReversedException::class);
        $this->journalPostingService->reverse($origJournal, $this->approver);
    }

    public function test_golden_scenario_10_period_lock_ubiquitous_enforcement(): void
    {
        $tx = $this->createSimpleTransaction('DIRECT_INCOME', 'IN', '500000.00', $this->bankBri308->id);
        $draft = $this->draftGenerator->generate($tx, $this->operator, ['bank_account' => $this->bankBri308]);

        // Close period
        $this->fiscalPeriodService->closePeriod($this->periodOpen, $this->admin);
        $tx->refresh();
        $this->assertTrue($tx->is_period_locked);

        // Pathway 1: approveAndPost blocked
        try {
            $this->postingCoordinator->approveAndPost($tx, $this->approver);
            $this->fail('Expected ClosedFiscalPeriodException on approveAndPost');
        } catch (ClosedFiscalPeriodException $e) {
            $this->assertTrue(true);
        }

        // Pathway 2: reject blocked
        try {
            $this->postingCoordinator->reject($tx, $this->operator, 'Alasan tolak');
            $this->fail('Expected ClosedFiscalPeriodException on reject');
        } catch (ClosedFiscalPeriodException $e) {
            $this->assertTrue(true);
        }

        // Pathway 3: direct post blocked
        try {
            $this->journalPostingService->post($draft, $this->approver);
            $this->fail('Expected ClosedFiscalPeriodException on post');
        } catch (ClosedFiscalPeriodException $e) {
            $this->assertTrue(true);
        }
    }

    // =========================================================================
    // ADVERSARIAL SECURITY & ACCOUNTING INTEGRITY TESTS
    // =========================================================================

    public function test_adversarial_maker_checker_creator_approving_own_rule_prevented(): void
    {
        $rule = ClassificationRule::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Self-Approved Rule',
            'rule_type' => 'KEYWORD',
            'direction' => 'IN',
            'pattern' => 'SELF',
            'pattern_type' => 'contains',
            'transaction_type' => 'DIRECT_INCOME',
            'target_account_id' => $this->accPendapatan4100->id,
            'auto_post' => true,
            'max_auto_post_amount' => '1000000.00',
            'version' => 1,
            'created_by' => $this->approver->id,
            'approved_by' => $this->approver->id, // Self approved!
            'approved_at' => now(),
            'is_active' => true,
        ]);

        $tx = $this->createSimpleTransaction('DIRECT_INCOME', 'IN', '100000.00', $this->bankBri308->id);
        $draft = $this->draftGenerator->generate($tx, $this->operator, ['bank_account' => $this->bankBri308]);

        $auth = AutomationPostingAuthorization::fromEntities($draft, $tx, $rule);

        $this->expectException(SegregationOfDutiesException::class);
        $this->journalPostingService->postSystemAutomated($draft, $auth);
    }

    public function test_adversarial_maker_checker_transaction_maker_approving_rule_prevented(): void
    {
        $rule = ClassificationRule::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Approver Approved Rule',
            'rule_type' => 'KEYWORD',
            'direction' => 'IN',
            'pattern' => 'OP',
            'pattern_type' => 'contains',
            'transaction_type' => 'DIRECT_INCOME',
            'target_account_id' => $this->accPendapatan4100->id,
            'auto_post' => true,
            'max_auto_post_amount' => '1000000.00',
            'version' => 1,
            'created_by' => $this->admin->id,
            'approved_by' => $this->approver->id, // Approver approved rule
            'approved_at' => now(),
            'is_active' => true,
        ]);

        $tx = $this->createSimpleTransaction('DIRECT_INCOME', 'IN', '100000.00', $this->bankBri308->id);
        // Draft created by approver (maker is same user who approved rule)
        $draft = $this->draftGenerator->generate($tx, $this->approver, ['bank_account' => $this->bankBri308]);

        $auth = AutomationPostingAuthorization::fromEntities($draft, $tx, $rule);

        $this->expectException(TransactionMakerCannotApproveRuleException::class);
        $this->journalPostingService->postSystemAutomated($draft, $auth);
    }

    public function test_adversarial_tenant_isolation_cross_tenant_injection_prevented(): void
    {
        $tx = $this->createSimpleTransaction('UNAPPLIED_CASH', 'IN', '500000.00', $this->bankBri308->id);

        $this->expectException(CrossTenantViolationException::class);

        // Attacker attempts to resolve using Org B's counterparty
        $this->unappliedCashHandler->resolve(
            transaction: $tx,
            resolutionData: ['counterparty_id' => $this->counterpartyOrgB->id],
            resolver: $this->approver
        );
    }

    public function test_adversarial_accounting_integrity_unbalanced_journal_rejected(): void
    {
        $entryDate = $this->periodOpen->start_date->format('Y-m-d');

        $draft = $this->journalPostingService->createDraft([
            'organization_id' => $this->orgA->id,
            'entry_date' => $entryDate,
            'description' => 'Unbalanced Attempt',
            'lines' => [
                ['account_id' => $this->accKas1110->id, 'debit' => '500000.00', 'credit' => '0.00'],
                ['account_id' => $this->accPendapatan4100->id, 'debit' => '0.00', 'credit' => '400000.00'], // Off by 100k
            ],
        ], $this->operator);

        $this->expectException(UnbalancedJournalException::class);
        $this->journalPostingService->post($draft, $this->approver);
    }

    public function test_adversarial_accounting_integrity_non_postable_header_account_rejected(): void
    {
        $headerAccount = Account::where('organization_id', $this->orgA->id)->where('is_postable', false)->firstOrFail();

        $entryDate = $this->periodOpen->start_date->format('Y-m-d');

        $this->expectException(NonPostableAccountException::class);

        $this->journalPostingService->createDraft([
            'organization_id' => $this->orgA->id,
            'entry_date' => $entryDate,
            'description' => 'Non-postable Header Account Attempt',
            'lines' => [
                ['account_id' => $headerAccount->id, 'debit' => '200000.00', 'credit' => '0.00'],
                ['account_id' => $this->accPendapatan4100->id, 'debit' => '0.00', 'credit' => '200000.00'],
            ],
        ], $this->operator);
    }

    public function test_adversarial_accounting_integrity_inactive_account_rejected(): void
    {
        $inactiveAccount = Account::create([
            'organization_id' => $this->orgA->id,
            'code' => '4999-INACTIVE',
            'name' => 'Pendapatan Nonaktif',
            'account_type' => Account::TYPE_REVENUE,
            'normal_balance' => Account::BALANCE_CREDIT,
            'is_postable' => true,
            'is_active' => false, // Inactive!
        ]);

        $this->expectException(InactiveAccountException::class);
        $this->accountMappingResolver->validateAccount($inactiveAccount, $this->orgA->id);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function createSimpleTransaction(
        string $type,
        string $direction,
        string $amount,
        int $bankAccountId
    ): BusinessTransaction {
        $bankTx = BankTransaction::create([
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $bankAccountId,
            'transaction_date' => '2026-03-12',
            'value_date' => '2026-03-12',
            'amount' => $amount,
            'direction' => $direction === 'OUT' ? BankTransactionDirection::OUT : BankTransactionDirection::IN,
            'description' => 'Golden Test '.$type,
            'reference_number' => 'REF-'.uniqid(),
            'fingerprint' => hash('sha256', uniqid('fp_', true)),
            'balance_after' => 10000000.00,
        ]);

        return BusinessTransaction::create([
            'organization_id' => $this->orgA->id,
            'transaction_number' => 'BTX-'.uniqid(),
            'transaction_date' => '2026-03-12',
            'transaction_type' => $type,
            'direction' => $direction,
            'amount' => $amount,
            'description' => 'Golden Test '.$type,
            'status' => 'pending',
            'is_period_locked' => false,
            'fiscal_period_id' => $this->periodOpen->id,
            'bank_transaction_id' => $bankTx->id,
            'counterparty_id' => $this->counterpartyMaarif->id,
        ]);
    }
}
