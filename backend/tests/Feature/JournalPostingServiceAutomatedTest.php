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

        $this->expectException(CrossTenantViolationException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_cross_tenant_business_transaction(): void
    {
        [$draft, $bizTx, $rule] = $this->createValidScenario();

        $orgBTx = TenantContext::forTenant($this->orgB->id, function () {
            $periodB = FiscalPeriod::create([
                'organization_id' => $this->orgB->id,
                'code' => '2026-03-B',
                'name' => 'Maret 2026 B',
                'start_date' => '2026-03-01',
                'end_date' => '2026-03-31',
                'status' => 'open',
            ]);

            return BusinessTransaction::create([
                'organization_id' => $this->orgB->id,
                'transaction_number' => 'BT-2026-99999',
                'fiscal_period_id' => $periodB->id,
                'transaction_date' => '2026-03-15',
                'transaction_type' => 'BANK_FEE_TAX',
                'direction' => 'OUT',
                'amount' => '25000.00',
                'description' => 'Cross-tenant tx',
                'status' => 'draft',
            ]);
        });

        // Auth refers to draft in Org A, but bizTx in Org B
        $auth = new AutomationPostingAuthorization(
            organizationId: $this->orgA->id,
            journalEntryId: $draft->id,
            businessTransactionId: $orgBTx->id,
            classificationRuleId: $rule->id,
            expectedRuleVersion: 1
        );

        $this->expectException(CrossTenantViolationException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_cross_tenant_classification_rule(): void
    {
        [$draft, $bizTx] = $this->createValidScenario();

        $orgBRule = TenantContext::forTenant($this->orgB->id, function () {
            return ClassificationRule::create([
                'organization_id' => $this->orgB->id,
                'name' => 'Org B Rule',
                'priority' => 10,
                'is_active' => true,
                'rule_type' => 'KEYWORD',
                'pattern' => 'PAJAK',
                'transaction_type' => 'BANK_FEE_TAX',
                'auto_post' => true,
                'max_auto_post_amount' => '500000.00',
                'version' => 1,
                'created_by' => $this->ruleCreatorAdmin->id,
                'approved_by' => $this->ruleApprover->id,
                'approved_at' => now(),
            ]);
        });

        $auth = new AutomationPostingAuthorization(
            organizationId: $this->orgA->id,
            journalEntryId: $draft->id,
            businessTransactionId: $bizTx->id,
            classificationRuleId: $orgBRule->id,
            expectedRuleVersion: 1
        );

        $this->expectException(CrossTenantViolationException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_unlinked_journal_source(): void
    {
        [$draft, $bizTx, $rule] = $this->createValidScenario();

        // Create an unrelated business transaction
        $unrelatedBizTx = BusinessTransaction::create([
            'organization_id' => $this->orgA->id,
            'transaction_number' => BusinessTransaction::generateTransactionNumber($this->orgA->id, 2026),
            'fiscal_period_id' => $this->periodMaret2026->id,
            'transaction_date' => '2026-03-15',
            'transaction_type' => 'BANK_FEE_TAX',
            'direction' => 'OUT',
            'amount' => '10000.00',
            'description' => 'Unrelated',
            'status' => 'draft',
        ]);

        $auth = AutomationPostingAuthorization::fromEntities($draft, $unrelatedBizTx, $rule);

        $this->expectException(InvalidAutomatedPostingSourceException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_already_posted_journal(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        // First post succeeds
        $this->postingService->postSystemAutomated($draft, $auth);

        // Second post must be rejected
        $this->expectException(AutomatedPostingAlreadyExecutedException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_already_posted_business_transaction(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $bizTx->status = 'posted';
        $bizTx->posted_journal_entry_id = $draft->id;
        $bizTx->save();

        $this->expectException(AutomatedPostingAlreadyExecutedException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_unapproved_rule(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $rule->approved_by = null;
        $rule->approved_at = null;
        $rule->save();

        $this->expectException(RuleNotApprovedException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_inactive_or_revoked_rule(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $rule->is_active = false;
        $rule->save();

        $this->expectException(RuleRevokedException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_inactive_rule_approver(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $this->ruleApprover->is_active = false;
        $this->ruleApprover->save();

        $this->expectException(UnauthorizedRuleApproverException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_approver_without_journal_approve_permission(): void
    {
        [$draft, $bizTx, $rule] = $this->createValidScenario();

        // Operator does NOT have journal.approve permission
        $rule->approved_by = $this->makerOperator->id;
        $rule->save();

        $auth = AutomationPostingAuthorization::fromEntities($draft, $bizTx, $rule);

        $this->expectException(UnauthorizedRuleApproverException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_rule_with_auto_post_disabled(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $rule->auto_post = false;
        $rule->save();

        $this->expectException(AutoPostDisabledException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_rule_with_zero_max_auto_post_amount(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $rule->max_auto_post_amount = '0.00';
        $rule->save();

        $this->expectException(AutoPostDisabledException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_transaction_amount_exceeding_threshold(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        // Lower threshold to Rp20.000 (Transaction is Rp25.000)
        $rule->max_auto_post_amount = '20000.00';
        $rule->save();

        $this->expectException(AutoPostAmountExceededException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_transaction_before_effective_from(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $rule->effective_from = '2026-04-01'; // Transaction is 2026-03-15
        $rule->save();

        $this->expectException(InvalidAutomationAuthorizationException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_transaction_after_effective_to(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $rule->effective_to = '2026-02-28'; // Transaction is 2026-03-15
        $rule->save();

        $this->expectException(InvalidAutomationAuthorizationException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_rule_version_mismatch(): void
    {
        [$draft, $bizTx, $rule] = $this->createValidScenario();

        // Auth expects version 1, but rule version in database has bumped to 2
        $rule->version = 2;
        $rule->save();

        $auth = new AutomationPostingAuthorization(
            organizationId: $this->orgA->id,
            journalEntryId: $draft->id,
            businessTransactionId: $bizTx->id,
            classificationRuleId: $rule->id,
            expectedRuleVersion: 1
        );

        $this->expectException(RuleSnapshotMismatchException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_rule_snapshot_hash_tampering(): void
    {
        [$draft, $bizTx, $rule] = $this->createValidScenario();

        $auth = new AutomationPostingAuthorization(
            organizationId: $this->orgA->id,
            journalEntryId: $draft->id,
            businessTransactionId: $bizTx->id,
            classificationRuleId: $rule->id,
            expectedRuleVersion: 1,
            expectedRuleSnapshotHash: 'forged_hash_value'
        );

        $this->expectException(RuleSnapshotMismatchException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_sod_when_rule_creator_equals_rule_approver(): void
    {
        [$draft, $bizTx, $rule] = $this->createValidScenario();

        // Self-approved rule
        $rule->created_by = $this->ruleApprover->id;
        $rule->approved_by = $this->ruleApprover->id;
        $rule->save();

        $auth = AutomationPostingAuthorization::fromEntities($draft, $bizTx, $rule);

        $this->expectException(SegregationOfDutiesException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_sod_when_transaction_maker_equals_rule_approver(): void
    {
        [$draft, $bizTx, $rule] = $this->createValidScenario();

        // Transaction maker is also the rule approver
        $draft->created_by = $this->ruleApprover->id;
        $draft->save();

        $auth = AutomationPostingAuthorization::fromEntities($draft, $bizTx, $rule);

        $this->expectException(TransactionMakerCannotApproveRuleException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_receivable_payment(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $bizTx->transaction_type = 'RECEIVABLE_PAYMENT';
        $bizTx->save();

        $this->expectException(InvalidAutomationAuthorizationException::class);
        $this->expectExceptionMessage('RECEIVABLE_PAYMENT is strictly blocked');
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_unapplied_cash(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $bizTx->transaction_type = 'UNAPPLIED_CASH';
        $bizTx->save();

        $this->expectException(InvalidAutomationAuthorizationException::class);
        $this->expectExceptionMessage('UNAPPLIED_CASH is prohibited');
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_ambiguous_bank_transfer(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $bizTx->transaction_type = 'BANK_TRANSFER';
        $bizTx->transfer_pairing_status = 'PAIRING_AMBIGUOUS';
        $bizTx->save();

        $this->expectException(InvalidAutomationAuthorizationException::class);
        $this->expectExceptionMessage('PAIRING_AMBIGUOUS');
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_unpaired_bank_transfer(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $bizTx->transaction_type = 'BANK_TRANSFER';
        $bizTx->transfer_pairing_status = 'PENDING_PAIRING';
        $bizTx->save();

        $this->expectException(InvalidAutomationAuthorizationException::class);
        $this->expectExceptionMessage('leg pairing status');
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_automated_rejects_closed_fiscal_period(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $this->periodMaret2026->status = FiscalPeriod::STATUS_CLOSED;
        $this->periodMaret2026->save();

        $this->expectException(ClosedFiscalPeriodException::class);
        $this->postingService->postSystemAutomated($draft, $auth);
    }

    public function test_system_posted_journal_is_immutable(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $posted = $this->postingService->postSystemAutomated($draft, $auth);

        $this->expectException(ImmutableJournalException::class);
        $posted->description = 'Tampered description';
        $posted->save();
    }

    public function test_system_posted_journal_can_be_reversed(): void
    {
        [$draft, $bizTx, $rule, $auth] = $this->createValidScenario();

        $posted = $this->postingService->postSystemAutomated($draft, $auth);

        $reversal = $this->postingService->reverse($posted, $this->ruleApprover, '2026-03-20', 'Koreksi kesalahan');

        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);
        $this->assertSame(JournalEntry::TYPE_REVERSAL, $reversal->entry_type);
        $this->assertSame($this->ruleApprover->id, $reversal->posted_by);

        $posted->refresh();
        $this->assertSame(JournalEntry::STATUS_REVERSED, $posted->status);
        $this->assertSame($reversal->id, $posted->reversed_entry_id);
    }
}
