<?php

namespace Tests\Feature;

use App\Domain\Accounting\DTOs\AutomationPostingAuthorization;
use App\Domain\Accounting\Exceptions\AutomatedPostingAlreadyExecutedException;
use App\Domain\Accounting\Exceptions\AutoPostAmountExceededException;
use App\Domain\Accounting\Exceptions\AutoPostDisabledException;
use App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domain\Accounting\Exceptions\ImmutableJournalException;
use App\Domain\Accounting\Exceptions\InvalidAutomatedPostingSourceException;
use App\Domain\Accounting\Exceptions\InvalidAutomationAuthorizationException;
use App\Domain\Accounting\Exceptions\RuleNotApprovedException;
use App\Domain\Accounting\Exceptions\RuleRevokedException;
use App\Domain\Accounting\Exceptions\RuleSnapshotMismatchException;
use App\Domain\Accounting\Exceptions\SegregationOfDutiesException;
use App\Domain\Accounting\Exceptions\TransactionMakerCannotApproveRuleException;
use App\Domain\Accounting\Exceptions\UnauthorizedRuleApproverException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\JournalDraftGenerator;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Bank\Enums\BankTransactionDirection;
use App\Domain\Bank\Models\BankAccount;
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

class JournalPostingServiceAutomatedTest extends TestCase
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

    private JournalDraftGenerator $draftGenerator;

    private JournalPostingService $postingService;

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

        $this->makerOperator = User::where('email', 'operator@maarif-cilacap.org')->firstOrFail();
        $this->ruleCreatorAdmin = User::where('email', 'admin@maarif-cilacap.org')->firstOrFail();
        $this->ruleApprover = User::where('email', 'approver@maarif-cilacap.org')->firstOrFail();

        $this->bankGiro308 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%308%')->firstOrFail();

        $this->accountBebanPajak = Account::where('organization_id', $this->orgA->id)
            ->where('code', '5300')->firstOrFail();

        $this->cpBankBri = Counterparty::where('organization_id', $this->orgA->id)
            ->where('name', 'LIKE', '%BRI%')->firstOrFail();

        $this->periodMaret2026 = FiscalPeriod::where('organization_id', $this->orgA->id)
            ->where('code', '2026-03')->firstOrFail();

        $this->draftGenerator = app(JournalDraftGenerator::class);
        $this->postingService = app(JournalPostingService::class);
    }

    private function createValidScenario(): array
    {
        $bankTx = BankTransaction::create([
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $this->bankGiro308->id,
            'transaction_date' => '2026-03-15',
            'direction' => BankTransactionDirection::OUT,
            'amount' => '25000.00',
            'description' => 'PAJAK BUNGA BANK',
            'fingerprint' => hash('sha256', uniqid('fp_', true)),
        ]);

        $rule = ClassificationRule::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Rule Pajak Bunga Bank',
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
        ]);

        $bizTx = BusinessTransaction::create([
            'organization_id' => $this->orgA->id,
            'transaction_number' => BusinessTransaction::generateTransactionNumber($this->orgA->id, 2026),
            'bank_transaction_id' => $bankTx->id,
            'fiscal_period_id' => $this->periodMaret2026->id,
            'transaction_date' => '2026-03-15',
            'transaction_type' => 'BANK_FEE_TAX',
            'direction' => 'OUT',
            'amount' => '25000.00',
            'target_account_id' => $this->accountBebanPajak->id,
            'counterparty_id' => $this->cpBankBri->id,
            'classification_status' => 'classified',
            'classification_method' => 'DETERMINISTIC_RULE',
            'auto_post_eligible' => true,
            'description' => 'Pajak Bunga Bank Maret 2026',
            'status' => 'draft',
            'rule_snapshot' => $rule->toSnapshot(),
        ]);

        $draft = $this->draftGenerator->generate($bizTx, $this->makerOperator, ['subtype' => 'TAX']);

        $auth = AutomationPostingAuthorization::fromEntities($draft, $bizTx, $rule);

        return [$draft, $bizTx, $rule, $auth];
    }

    public function test_post_system_automated_success_end_to_end(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $postedJournal = $this->postingService->postSystemAutomated($draft, $auth);

        // 1. Journal status & attribution
        $this->assertSame(JournalEntry::STATUS_POSTED, $postedJournal->status);
        $this->assertNull($postedJournal->posted_by, 'Zero identity spoofing: posted_by must remain NULL for system daemon');
        $this->assertSame($rule->id, $postedJournal->posted_by_rule_id, 'Must reference approved classification rule');
        $this->assertNotNull($postedJournal->posted_at);
        $this->assertSame($this->makerOperator->id, $postedJournal->created_by, 'Transaction maker must be preserved');

        // 2. BusinessTransaction linkage & state transition
        $bizTx->refresh();
        $this->assertSame('posted', $bizTx->status);
        $this->assertSame($postedJournal->id, $bizTx->posted_journal_entry_id);
        $this->assertNull($bizTx->posted_by);
        $this->assertNotNull($bizTx->posted_at);

        // 3. Audit trail verification (SYSTEM_DAEMON)
        $audit = AuditLog::where('model_type', JournalEntry::class)
            ->where('model_id', $postedJournal->id)
            ->where('action', 'JOURNAL_POSTED_AUTOMATED')
            ->first();

        $this->assertNotNull($audit, 'JOURNAL_POSTED_AUTOMATED audit log must be recorded');
        $this->assertNull($audit->user_id, 'Audit log user_id must be NULL (no human user spoofed)');
        $this->assertSame('SYSTEM_DAEMON', $audit->new_values['executor'] ?? null);
        $this->assertSame($rule->id, $audit->new_values['posted_by_rule_id'] ?? null);
        $this->assertSame($this->ruleApprover->id, $audit->new_values['rule_approver_id'] ?? null);
        $this->assertSame($this->ruleCreatorAdmin->id, $audit->new_values['rule_creator_id'] ?? null);
        $this->assertSame($this->makerOperator->id, $audit->new_values['transaction_maker_id'] ?? null);
    }

    public function test_existing_manual_post_still_works_with_checker(): void
    {
        [$draft] = $this->createValidScenario();

        $postedJournal = $this->postingService->post($draft, $this->ruleApprover);

        $this->assertSame(JournalEntry::STATUS_POSTED, $postedJournal->status);
        $this->assertSame($this->ruleApprover->id, $postedJournal->posted_by);
        $this->assertNull($postedJournal->posted_by_rule_id);

        $audit = AuditLog::where('model_type', JournalEntry::class)
            ->where('model_id', $postedJournal->id)
            ->where('action', 'JOURNAL_POSTED')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($this->ruleApprover->id, $audit->user_id);
    }

    public function test_existing_manual_post_still_enforces_maker_checker_sod(): void
    {
        [$draft] = $this->createValidScenario();

        $this->expectException(SegregationOfDutiesException::class);
        $this->postingService->post($draft, $this->makerOperator);
    }

    public function test_system_automated_rejects_cross_tenant_authorization(): void
    {
        [$draft, $bizTx, $rule] = $this->createValidScenario();

        $auth = new AutomationPostingAuthorization(
            organizationId: $this->orgB->id, // Mismatch
            journalEntryId: $draft->id,
            businessTransactionId: $bizTx->id,
            classificationRuleId: $rule->id,
            expectedRuleVersion: 1
        );

