<?php

namespace Tests\Unit;

use App\Domain\Accounting\Enums\AutoPostDecisionOutcome;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\AutoPostEligibilityPolicy;
use App\Domain\Bank\Enums\BankTransactionDirection;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Database\Seeders\CoaSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoPostEligibilityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private User $makerOperator;

    private User $ruleCreatorAdmin;

    private User $ruleApprover;

    private BankAccount $bankGiro308;

    private Account $accountBebanPajak;

    private Counterparty $cpBankBri;

    private FiscalPeriod $periodMaret2026;

    private AutoPostEligibilityPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(OrganizationSeeder::class);
        $this->seed(CoaSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $this->orgA = Organization::where('code', 'LP-MAARIF-CLP')->firstOrFail();
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

        $this->policy = app(AutoPostEligibilityPolicy::class);
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
            'name' => 'Rule Pajak Bunga Bank Test',
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

    private function createDummyJournalEntry(string $status = 'draft'): JournalEntry
    {
        return JournalEntry::create([
            'organization_id' => $this->orgA->id,
            'fiscal_period_id' => $this->periodMaret2026->id,
            'entry_number' => 'JV-TEST-'.uniqid(),
            'entry_date' => '2026-03-15',
            'entry_type' => JournalEntry::TYPE_PAYMENT,
            'status' => $status,
            'description' => 'Test Dummy Journal Entry',
            'posted_at' => $status === 'posted' ? now() : null,
            'posted_by' => $status === 'posted' ? $this->ruleApprover->id : null,
        ]);
    }

    private function createValidTransaction(ClassificationRule $rule, array $overrides = []): BusinessTransaction
    {
        $bankTx = BankTransaction::create([
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $this->bankGiro308->id,
            'transaction_date' => '2026-03-15',
            'value_date' => '2026-03-15',
            'description' => 'PAJAK BUNGA BANK MARET 2026',
            'reference_number' => 'REF-UNIT-'.uniqid(),
            'direction' => BankTransactionDirection::OUT,
            'amount' => '25000.00',
            'fingerprint' => hash('sha256', uniqid('fp_', true)),
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

    public function test_eligible_transaction_evaluates_to_eligible(): void
    {
        $rule = $this->createValidRule();
        $tx = $this->createValidTransaction($rule);

        $eval = $this->policy->evaluate($tx);

        $this->assertTrue($eval->isEligible());
        $this->assertSame(AutoPostDecisionOutcome::ELIGIBLE, $eval->outcome);
        $this->assertSame('ELIGIBLE', $eval->reasonCode);
    }

    public function test_already_posted_transaction_evaluates_to_already_posted(): void
    {
        $rule = $this->createValidRule();
        $journal = $this->createDummyJournalEntry('posted');
        $tx = $this->createValidTransaction($rule, [
            'status' => 'posted',
            'posted_journal_entry_id' => $journal->id,
        ]);

        $eval = $this->policy->evaluate($tx);

        $this->assertFalse($eval->isEligible());
        $this->assertSame(AutoPostDecisionOutcome::ALREADY_POSTED, $eval->outcome);
    }

    public function test_rejected_transaction_evaluates_to_blocked(): void
    {
        $rule = $this->createValidRule();
        $tx = $this->createValidTransaction($rule, [
            'status' => 'rejected',
        ]);

        $eval = $this->policy->evaluate($tx);
