<?php

namespace Tests\Unit;

use App\Domain\Accounting\Enums\AutoPostDecisionOutcome;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\AutoPostExecutionCoordinator;
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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutoPostExecutionCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $makerOperator;

    private User $ruleCreatorAdmin;

    private User $ruleApprover;

    private BankAccount $bankGiro308;

    private Account $accountBebanPajak;

    private Counterparty $cpBankBri;

    private FiscalPeriod $periodMaret2026;

    private TransactionCategory $catPajakBunga;

    private BankImport $bankImport;

    private AutoPostExecutionCoordinator $coordinator;

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

        $this->makerOperator = User::where('email', 'operator@maarif-cilacap.org')->firstOrFail();
        $this->ruleCreatorAdmin = User::where('email', 'admin@maarif-cilacap.org')->firstOrFail();
        $this->ruleApprover = User::where('email', 'approver@maarif-cilacap.org')->firstOrFail();

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
            'imported_by' => $this->makerOperator->id,
            'filename' => 'bri_maret_2026.csv',
            'format' => 'csv',
            'file_hash' => hash('sha256', 'dummy_import_coordinator_test'),
            'total_rows' => 10,
            'status' => 'done',
        ]);

        $this->coordinator = app(AutoPostExecutionCoordinator::class);
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
            'name' => 'Rule Pajak Bunga Bank AutoPost',
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
            'created_by' => $this->ruleCreatorAdmin->id,
            'approved_by' => $this->ruleApprover->id,
            'approved_at' => now(),
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ];

        return ClassificationRule::create(array_merge($defaults, $overrides));
    }

    private function createValidTransaction(ClassificationRule $rule, array $overrides = []): BusinessTransaction
    {
        $bankTx = BankTransaction::create([
            'organization_id' => $overrides['organization_id'] ?? $this->orgA->id,
            'bank_account_id' => $this->bankGiro308->id,
            'bank_import_id' => $this->bankImport->id,
            'transaction_date' => $overrides['transaction_date'] ?? '2026-03-15',
            'value_date' => $overrides['value_date'] ?? '2026-03-15',
            'description' => 'PAJAK BUNGA BANK MARET 2026',
            'reference_number' => 'REF-COORD-'.uniqid(),
            'direction' => BankTransactionDirection::OUT,
            'amount' => $overrides['amount'] ?? '25000.00',
            'fingerprint' => hash('sha256', uniqid('fp_coord_', true)),
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

    public function test_execute_eligible_transaction_posts_successfully(): void
    {
        $rule = $this->createValidRule();
        $tx = $this->createValidTransaction($rule);

        $result = $this->coordinator->execute($tx);

        $this->assertTrue($result['success']);
        $this->assertSame(AutoPostDecisionOutcome::ELIGIBLE->value, $result['outcome']);
        $this->assertFalse($result['is_duplicate']);
        $this->assertNotNull($result['data']['journal_entry_id']);

        $txFresh = $tx->fresh();
        $this->assertSame('posted', $txFresh->status);
        $this->assertNotNull($txFresh->posted_journal_entry_id);

        $journal = JournalEntry::find($result['data']['journal_entry_id']);
        $this->assertNotNull($journal);
        $this->assertSame('posted', $journal->status);
        $this->assertNull($journal->posted_by);
        $this->assertSame($rule->id, $journal->posted_by_rule_id);
        $this->assertNotNull($journal->posted_at);

        // Verify audit trail
        $auditLog = DB::table('audit_logs')
            ->where('model_type', JournalEntry::class)
            ->where('model_id', $journal->id)
            ->where('action', 'JOURNAL_POSTED_AUTOMATED')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertNull($auditLog->user_id);
    }

    public function test_execute_already_posted_transaction_returns_idempotent_result(): void
    {
        $rule = $this->createValidRule();
        $tx = $this->createValidTransaction($rule);

        // 1st run: Post it
        $firstResult = $this->coordinator->execute($tx);
        $this->assertTrue($firstResult['success']);
        $this->assertSame(AutoPostDecisionOutcome::ELIGIBLE->value, $firstResult['outcome']);

        // 2nd run: Replay
        $secondResult = $this->coordinator->execute($tx->fresh());
        $this->assertTrue($secondResult['success']);
        $this->assertSame(AutoPostDecisionOutcome::ALREADY_POSTED->value, $secondResult['outcome']);
        $this->assertTrue($secondResult['is_duplicate']);
        $this->assertSame($firstResult['data']['journal_entry_id'], $secondResult['data']['journal_entry_id']);
    }

    public function test_execute_blocked_transaction_stores_posting_error(): void
    {
        $rule = $this->createValidRule(['transaction_type' => 'RECEIVABLE_PAYMENT']);
        $tx = $this->createValidTransaction($rule, [
