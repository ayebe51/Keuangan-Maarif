<?php

namespace Tests\Unit;

use App\Domain\Accounting\Exceptions\InvalidWorkflowStateException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntrySource;
use App\Domain\Accounting\Services\UnappliedCashHandler;
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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnappliedCashHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $maker;

    private User $approver;

    private BankAccount $bankGiro308;

    private Account $accUnapplied2200;

    private Account $accPendapatan4100;

    private FiscalPeriod $periodMaret2026;

    private UnappliedCashHandler $handler;

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

        $this->accUnapplied2200 = Account::where('organization_id', $this->orgA->id)
            ->where('code', '2200')->firstOrFail();

        $this->accPendapatan4100 = Account::where('organization_id', $this->orgA->id)
            ->where('code', '4100')->firstOrFail();

        $this->periodMaret2026 = FiscalPeriod::where('organization_id', $this->orgA->id)
            ->where('code', '2026-03')->firstOrFail();

        $this->handler = app(UnappliedCashHandler::class);
    }

    protected function tearDown(): void
    {
        TenantContext::setTenantId(null);
        parent::tearDown();
    }

    private function createSampleUnappliedTransaction(float $amount = 750000.00, string $direction = 'IN'): BusinessTransaction
    {
        $bankTx = BankTransaction::create([
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $this->bankGiro308->id,
            'transaction_date' => '2026-03-15',
            'value_date' => '2026-03-15',
            'description' => 'Setoran titipan belum jelas',
            'reference_number' => 'REF-UNAPP-'.uniqid(),
            'direction' => $direction === 'IN' ? BankTransactionDirection::IN : BankTransactionDirection::OUT,
            'amount' => $amount,
            'fingerprint' => hash('sha256', uniqid('fp_unapp_', true)),
            'balance_after' => 12000000.00,
        ]);

        return BusinessTransaction::create([
            'organization_id' => $this->orgA->id,
            'transaction_number' => BusinessTransaction::generateTransactionNumber($this->orgA->id, 2026),
            'bank_transaction_id' => $bankTx->id,
            'fiscal_period_id' => $this->periodMaret2026->id,
            'transaction_date' => '2026-03-15',
            'value_date' => '2026-03-15',
            'transaction_type' => 'UNAPPLIED_CASH',
            'direction' => $direction,
            'amount' => $amount,
            'target_account_id' => $this->accUnapplied2200->id,
            'classification_status' => ClassificationStatus::CLASSIFIED->value,
            'auto_post_eligible' => false,
            'description' => 'Penerimaan dana titipan belum jelas',
            'status' => 'draft',
        ]);
    }

    public function test_identify_unapplied_cash_business_transaction_type(): void
    {
        $tx = $this->createSampleUnappliedTransaction(500000.00);

        $this->assertTrue($this->handler->identify($tx));
    }

    public function test_identify_unapplied_cash_by_target_account_2200(): void
    {
        $tx = $this->createSampleUnappliedTransaction(300000.00);
        $tx->transaction_type = 'DIRECT_INCOME'; // Even if type is direct income
        $tx->target_account_id = $this->accUnapplied2200->id;
        $tx->save();

        $this->assertTrue($this->handler->identify($tx));
    }

    public function test_identify_unapplied_cash_bank_statement(): void
    {
        $bankTx = BankTransaction::create([
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $this->bankGiro308->id,
            'transaction_date' => '2026-03-15',
            'value_date' => '2026-03-15',
            'description' => 'Transfer Titipan Donatur Tanpa Identitas',
            'reference_number' => 'REF-UNAPP-STMT',
            'direction' => BankTransactionDirection::IN,
            'amount' => 1000000.00,
            'fingerprint' => hash('sha256', uniqid('fp_stmt_', true)),
            'balance_after' => 12000000.00,
        ]);

        $this->assertTrue($this->handler->identify($bankTx));
    }

    public function test_identify_rejects_out_direction(): void
    {
        $txOut = $this->createSampleUnappliedTransaction(250000.00, 'OUT');

        $this->assertFalse($this->handler->identify($txOut));
    }

    public function test_create_draft_isolates_unapplied_cash_to_liability_account_2200(): void
    {
        $tx = $this->createSampleUnappliedTransaction(850000.00);

        $draft = $this->handler->createDraft($tx, $this->maker);

        $this->assertNotNull($draft);
        $this->assertSame(JournalEntry::STATUS_DRAFT, $draft->status);
        $this->assertSame('850000.00', number_format((float) $draft->lines->sum('debit'), 2, '.', ''));
        $this->assertSame('850000.00', number_format((float) $draft->lines->sum('credit'), 2, '.', ''));

        // Lines verification:
        // Debit: Bank Giro 308 (1120)
        // Credit: Liability Unapplied Cash (2200)
        $lines = $draft->lines;
        $this->assertCount(2, $lines);

        $debitLine = $lines->firstWhere('debit', '>', 0);
        $creditLine = $lines->firstWhere('credit', '>', 0);

        $this->assertSame($this->bankGiro308->account_id, $debitLine->account_id);
        $this->assertSame($this->accUnapplied2200->id, $creditLine->account_id);

        // Verify credit account type is liability (NOT REVENUE!)
        $creditAccount = Account::find($creditLine->account_id);
        $this->assertSame(Account::TYPE_LIABILITY, $creditAccount->account_type);

        // Canonical Source-of-Truth verification
        $source = JournalEntrySource::where('journal_entry_id', $draft->id)->first();
        $this->assertNotNull($source);
        $this->assertSame($tx->id, (int) $source->source_id);
        $this->assertSame(JournalEntrySource::ROLE_PRIMARY, $source->leg_role);

        $this->assertSame($draft->id, $tx->fresh()->posted_journal_entry_id);
    }

    public function test_create_draft_rejects_out_direction(): void
    {
        $txOut = $this->createSampleUnappliedTransaction(300000.00, 'OUT');

        $this->expectException(InvalidWorkflowStateException::class);
        $this->expectExceptionMessage('Unapplied cash must be an incoming receipt (direction IN)');

        $this->handler->createDraft($txOut, $this->maker);
    }

    public function test_create_draft_rejects_already_posted(): void
    {
        $tx = $this->createSampleUnappliedTransaction(400000.00);
        $tx->status = 'posted';
        $tx->save();

        $this->expectException(InvalidWorkflowStateException::class);
        $this->expectExceptionMessage('has already been posted');

        $this->handler->createDraft($tx, $this->maker);
    }

    public function test_resolve_unapplied_cash_by_human_reviewer(): void
    {
        $tx = $this->createSampleUnappliedTransaction(1500000.00);
        $draft = $this->handler->createDraft($tx, $this->maker);

        $this->assertNotNull(JournalEntry::find($draft->id));

        // Reviewer identifies sender as infaq donor -> resolves to revenue 4100
        $cpDarwata = Counterparty::where('organization_id', $this->orgA->id)->firstOrFail();

        $resolved = $this->handler->resolve($tx, [
            'target_account_id' => $this->accPendapatan4100->id,
            'counterparty_id' => $cpDarwata->id,
            'transaction_type' => 'DIRECT_INCOME',
            'notes' => 'Terkonfirmasi donasi infaq madrasah dari Darwata',
        ], $this->approver);

        $this->assertSame($this->accPendapatan4100->id, $resolved->target_account_id);
        $this->assertSame($cpDarwata->id, $resolved->counterparty_id);
        $this->assertSame('DIRECT_INCOME', $resolved->transaction_type);
        $this->assertSame(ClassificationStatus::REVIEWED, $resolved->classification_status);
        $this->assertStringContainsString('Terkonfirmasi donasi infaq', $resolved->notes);

        // Previous unapplied draft journal should be cleanly removed for new generation
        $this->assertNull(JournalEntry::find($draft->id));
        $this->assertNull($resolved->posted_journal_entry_id);

        // Audit log verification
        $auditLog = DB::table('audit_logs')
            ->where('model_type', BusinessTransaction::class)
            ->where('model_id', $tx->id)
            ->where('action', 'UNAPPLIED_CASH_RESOLVED')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame($this->approver->id, (int) $auditLog->user_id);
    }

    public function test_resolve_rejects_cross_tenant(): void
    {
        $tx = $this->createSampleUnappliedTransaction(500000.00);

        TenantContext::setTenantId($this->orgB->id);

        $this->expectException(CrossTenantViolationException::class);
        $this->handler->resolve($tx, [
            'target_account_id' => $this->accPendapatan4100->id,
        ], $this->approver);
    }

    public function test_unapplied_cash_queue_query(): void
    {
        $tx1 = $this->createSampleUnappliedTransaction(200000.00);
        $tx2 = $this->createSampleUnappliedTransaction(300000.00);

        $txPosted = $this->createSampleUnappliedTransaction(400000.00);
        $txPosted->status = 'posted';
        $txPosted->save();

        $queueIds = $this->handler->getUnappliedCashQueueQuery($this->orgA->id)->pluck('id')->all();

        $this->assertContains($tx1->id, $queueIds);
        $this->assertContains($tx2->id, $queueIds);
        $this->assertNotContains($txPosted->id, $queueIds);
    }
}
