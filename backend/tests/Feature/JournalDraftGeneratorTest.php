<?php

namespace Tests\Feature;

use App\Domain\Accounting\DTOs\BankTransferContext;
use App\Domain\Accounting\Exceptions\AlreadyPostedException;
use App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntrySource;
use App\Domain\Accounting\Services\JournalDraftGenerator;
use App\Domain\Bank\Enums\BankTransactionDirection;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Models\BankTransaction;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalDraftGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $makerA;

    private BankAccount $bankGiro308;

    private BankAccount $bankGiro304;

    private Counterparty $cpDarwata;

    private Fund $fundAbadi;

    private FiscalPeriod $periodMaret2026;

    private JournalDraftGenerator $generator;

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

        $this->makerA = User::where('email', 'operator@maarif-cilacap.org')->firstOrFail();

        $this->bankGiro308 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%308%')->firstOrFail();
        $this->bankGiro304 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%304%')->firstOrFail();

        $this->cpDarwata = Counterparty::where('organization_id', $this->orgA->id)
            ->where('name', 'LIKE', '%Darwata%')->firstOrFail();

        $this->fundAbadi = Fund::where('organization_id', $this->orgA->id)
            ->where('code', 'DANA-ABADI')->firstOrFail();

        $this->periodMaret2026 = FiscalPeriod::where('organization_id', $this->orgA->id)
            ->where('code', '2026-03')->firstOrFail();

        $this->generator = app(JournalDraftGenerator::class);
    }

    private function createBusinessTransaction(array $overrides = []): BusinessTransaction
    {
        $bankTx = BankTransaction::create([
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $this->bankGiro308->id,
            'transaction_date' => '2026-03-15',
            'direction' => BankTransactionDirection::IN,
            'amount' => '1000000.00',
            'description' => 'Mutasi Test',
            'fingerprint' => hash('sha256', uniqid('fp_', true)),
        ]);

        $defaults = [
            'organization_id' => $this->orgA->id,
            'transaction_number' => BusinessTransaction::generateTransactionNumber($this->orgA->id, 2026),
            'bank_transaction_id' => $bankTx->id,
            'fiscal_period_id' => $this->periodMaret2026->id,
            'transaction_date' => '2026-03-15',
            'transaction_type' => 'DIRECT_INCOME',
            'direction' => 'IN',
            'amount' => '1000000.00',
            'counterparty_id' => $this->cpDarwata->id,
            'description' => 'Pendapatan Donasi Pendidikan',
            'status' => 'draft',
        ];

        return BusinessTransaction::create(array_merge($defaults, $overrides));
    }

    public function test_business_transaction_generates_draft_journal_successfully(): void
    {
        $tx = $this->createBusinessTransaction([
            'transaction_type' => 'DIRECT_INCOME',
            'direction' => 'IN',
            'amount' => '1500000.00',
            'description' => 'Penerimaan Donasi Tunai',
        ]);

        $draft = $this->generator->generate($tx, $this->makerA);

        $this->assertInstanceOf(JournalEntry::class, $draft);
        $this->assertTrue($draft->isDraft());
        $this->assertSame(JournalEntry::STATUS_DRAFT, $draft->status);
        $this->assertNull($draft->posted_at);
        $this->assertNull($draft->posted_by);
        $this->assertSame($this->makerA->id, $draft->created_by);
        $this->assertSame($this->periodMaret2026->id, $draft->fiscal_period_id);
        $this->assertSame('1500000.00', $draft->lines()->firstWhere('debit', '>', 0)->debit);

        // Verify compatibility pointer on journal_entries
        $this->assertSame($tx->id, $draft->business_transaction_id);

        // Verify posted pointer on business_transactions remains NULL
        $this->assertNull($tx->fresh()->posted_journal_entry_id);

        // Verify canonical source in journal_entry_sources table
        $this->assertDatabaseHas('journal_entry_sources', [
            'organization_id' => $this->orgA->id,
            'journal_entry_id' => $draft->id,
            'source_type' => 'business_transaction',
            'source_id' => $tx->id,
            'business_transaction_id' => $tx->id,
            'leg_role' => 'primary',
        ]);
    }

    public function test_journal_lines_have_proper_dimensions(): void
    {
        $tx = $this->createBusinessTransaction([
            'transaction_type' => 'DIRECT_INCOME',
            'direction' => 'IN',
            'amount' => '500000.00',
            'counterparty_id' => $this->cpDarwata->id,
        ]);

        $draft = $this->generator->generate($tx, $this->makerA);
        $lines = $draft->lines()->get();

        $this->assertCount(2, $lines);

        $bankLine = $lines->firstWhere('account_id', Account::where('code', '1120')->first()->id);
        $this->assertNotNull($bankLine);
        $this->assertSame('500000.00', $bankLine->debit);
        $this->assertSame($this->bankGiro308->id, $bankLine->bank_account_id);

        $revenueLine = $lines->firstWhere('account_id', Account::where('code', '4100')->first()->id);
        $this->assertNotNull($revenueLine);
        $this->assertSame('500000.00', $revenueLine->credit);
        $this->assertSame($this->cpDarwata->id, $revenueLine->counterparty_id);
    }

    public function test_multi_line_overpayment_creates_balanced_three_line_draft(): void
    {
        $tx = $this->createBusinessTransaction([
            'transaction_type' => 'RECEIVABLE_PAYMENT',
            'direction' => 'IN',
            'amount' => '1000000.00',
            'counterparty_id' => $this->cpDarwata->id,
            'description' => 'Pelunasan dan Titipan MI Darwata',
        ]);

        $draft = $this->generator->generate($tx, $this->makerA, [
            'is_overpayment' => true,
            'receivable_amount' => '852000.00',
            'unapplied_amount' => '148000.00',
        ]);

        $lines = $draft->lines()->get();
        $this->assertCount(3, $lines);

        $bankLine = $lines->firstWhere('debit', '1000000.00');
        $this->assertNotNull($bankLine);

        $receivableLine = $lines->firstWhere('account_id', Account::where('code', '1210')->first()->id);
        $this->assertNotNull($receivableLine);
        $this->assertSame('852000.00', $receivableLine->credit);

        $unappliedLine = $lines->firstWhere('account_id', Account::where('code', '2200')->first()->id);
        $this->assertNotNull($unappliedLine);
        $this->assertSame('148000.00', $unappliedLine->credit);
        $this->assertSame(Account::TYPE_LIABILITY, $unappliedLine->account->account_type);
    }

    public function test_repeated_generation_request_returns_existing_draft_idempotently(): void
    {
        $tx = $this->createBusinessTransaction([
            'transaction_type' => 'DIRECT_INCOME',
            'amount' => '2000000.00',
        ]);

        // First generation
        $draft1 = $this->generator->generate($tx, $this->makerA);

        // Second generation (duplicate call)
        $draft2 = $this->generator->generate($tx, $this->makerA);

        // Must return the exact same draft without duplicating
        $this->assertSame($draft1->id, $draft2->id);
        $this->assertSame($draft1->entry_number, $draft2->entry_number);
        $this->assertSame(1, JournalEntrySource::where('source_id', $tx->id)->count());
    }

    public function test_already_posted_business_transaction_cannot_generate_new_draft(): void
    {
        $tx = $this->createBusinessTransaction([
            'status' => 'posted',
        ]);

        $this->expectException(AlreadyPostedException::class);
        $this->generator->generate($tx, $this->makerA);
    }

    public function test_cross_tenant_business_transaction_rejected(): void
    {
        $txOrgB = TenantContext::forTenant($this->orgB->id, function () {
            $periodB = FiscalPeriod::create([
                'organization_id' => $this->orgB->id,
                'code' => '2026-03',
                'name' => 'Maret 2026 Org B',
                'start_date' => '2026-03-01',
                'end_date' => '2026-03-31',
                'status' => FiscalPeriod::STATUS_OPEN,
            ]);

            return BusinessTransaction::create([
                'organization_id' => $this->orgB->id,
                'transaction_number' => 'BT-ORGB-001',
                'fiscal_period_id' => $periodB->id,
                'transaction_date' => '2026-03-15',
                'transaction_type' => 'DIRECT_INCOME',
                'direction' => 'IN',
                'amount' => '500000.00',
                'description' => 'Tx Org B',
                'status' => 'draft',
            ]);
        });

        $this->expectException(CrossTenantViolationException::class);
        $this->generator->generate($txOrgB, $this->makerA);
    }

    public function test_closed_fiscal_period_blocks_draft_generation(): void
    {
        // Close Maret 2026
        $this->periodMaret2026->update(['status' => FiscalPeriod::STATUS_CLOSED]);

        $tx = $this->createBusinessTransaction([
            'transaction_date' => '2026-03-15',
        ]);

        $this->expectException(ClosedFiscalPeriodException::class);
        $this->generator->generate($tx, $this->makerA);
    }

    public function test_bank_fee_tax_draft_generation_with_admin_fee_subtype(): void
    {
        $tx = $this->createBusinessTransaction([
            'transaction_type' => 'BANK_FEE_TAX',
            'direction' => 'OUT',
            'amount' => '25000.00',
            'description' => 'Biaya Administrasi Bank BRI',
        ]);

        $draft = $this->generator->generate($tx, $this->makerA, [
            'subtype' => 'ADMIN_FEE',
        ]);

        $this->assertTrue($draft->isDraft());
        $lines = $draft->lines()->get();

        $feeLine = $lines->firstWhere('account_id', Account::where('code', '5200')->first()->id);
        $this->assertNotNull($feeLine);
        $this->assertSame('25000.00', $feeLine->debit);

        $bankLine = $lines->firstWhere('account_id', Account::where('code', '1120')->first()->id);
        $this->assertNotNull($bankLine);
        $this->assertSame('25000.00', $bankLine->credit);
    }

    public function test_fund_allocation_disbursement_draft_generation(): void
    {
        $tx = $this->createBusinessTransaction([
            'transaction_type' => 'ALLOCATION_DISBURSEMENT',
            'direction' => 'OUT',
            'amount' => '5000000.00',
            'fund_id' => $this->fundAbadi->id,
            'description' => 'Penyaluran Beasiswa Dana Abadi',
        ]);

        $draft = $this->generator->generate($tx, $this->makerA);

        $this->assertTrue($draft->isDraft());
        $lines = $draft->lines()->get();

        $fundLine = $lines->firstWhere('account_id', Account::where('code', '2110')->first()->id);
        $this->assertNotNull($fundLine);
        $this->assertSame('5000000.00', $fundLine->debit);
        $this->assertSame($this->fundAbadi->id, $fundLine->fund_id);

        $bankLine = $lines->firstWhere('account_id', Account::where('code', '1120')->first()->id);
        $this->assertNotNull($bankLine);
        $this->assertSame('5000000.00', $bankLine->credit);
    }

    public function test_bank_transfer_draft_generation_with_paired_context(): void
    {
        $tx = $this->createBusinessTransaction([
            'transaction_type' => 'BANK_TRANSFER',
            'direction' => 'TRANSFER',
            'amount' => '10000000.00',
            'description' => 'Pemindahbukuan dari Giro 308 ke Giro 304',
        ]);

        $transferContext = new BankTransferContext(
            sourceBankAccountId: $this->bankGiro308->id,
            destinationBankAccountId: $this->bankGiro304->id
        );

        $draft = $this->generator->generate($tx, $this->makerA, [
            'transfer_context' => $transferContext,
            'source_bank_account' => $this->bankGiro308,
            'destination_bank_account' => $this->bankGiro304,
        ]);

        $this->assertTrue($draft->isDraft());
        $lines = $draft->lines()->get();

        $destLine = $lines->firstWhere('account_id', Account::where('code', '1130')->first()->id);
        $this->assertNotNull($destLine);
        $this->assertSame('10000000.00', $destLine->debit);
        $this->assertSame($this->bankGiro304->id, $destLine->bank_account_id);

        $sourceLine = $lines->firstWhere('account_id', Account::where('code', '1120')->first()->id);
        $this->assertNotNull($sourceLine);
        $this->assertSame('10000000.00', $sourceLine->credit);
        $this->assertSame($this->bankGiro308->id, $sourceLine->bank_account_id);
    }
}
