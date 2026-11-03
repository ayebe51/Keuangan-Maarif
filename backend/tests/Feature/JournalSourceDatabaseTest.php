<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntrySource;
use App\Domain\Accounting\Services\JournalDraftGenerator;
use App\Domain\Bank\Enums\BankTransactionDirection;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Database\Seeders\CoaSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JournalSourceDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private User $makerA;

    private BankAccount $bankGiro308;

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
        TenantContext::setTenantId($this->orgA->id);

        $this->makerA = User::where('email', 'operator@maarif-cilacap.org')->firstOrFail();

        $this->bankGiro308 = BankAccount::where('organization_id', $this->orgA->id)
            ->where('account_number', 'LIKE', '%308%')->firstOrFail();

        $this->periodMaret2026 = FiscalPeriod::where('organization_id', $this->orgA->id)
            ->where('code', '2026-03')->firstOrFail();

        $this->generator = app(JournalDraftGenerator::class);
    }

    private function createBusinessTransaction(): BusinessTransaction
    {
        $bankTx = BankTransaction::create([
            'organization_id' => $this->orgA->id,
            'bank_account_id' => $this->bankGiro308->id,
            'transaction_date' => '2026-03-15',
            'direction' => BankTransactionDirection::IN,
            'amount' => '1000000.00',
            'description' => 'Mutasi Test DB',
            'fingerprint' => hash('sha256', uniqid('fp_', true)),
        ]);

        return BusinessTransaction::create([
            'organization_id' => $this->orgA->id,
            'transaction_number' => BusinessTransaction::generateTransactionNumber($this->orgA->id, 2026),
            'bank_transaction_id' => $bankTx->id,
            'fiscal_period_id' => $this->periodMaret2026->id,
            'transaction_date' => '2026-03-15',
            'transaction_type' => 'DIRECT_INCOME',
            'direction' => 'IN',
            'amount' => '1000000.00',
            'description' => 'Pendapatan Donasi DB Check',
            'status' => 'draft',
        ]);
    }

    public function test_unique_source_constraint_prevents_duplicate_source_entry(): void
    {
        $tx = $this->createBusinessTransaction();
        $draft = $this->generator->generate($tx, $this->makerA);

        $this->assertDatabaseHas('journal_entry_sources', [
            'organization_id' => $this->orgA->id,
            'source_type' => 'business_transaction',
            'source_id' => $tx->id,
            'journal_entry_id' => $draft->id,
        ]);

        // Second manual insert with same organization_id, source_type, source_id MUST violate unique constraint
        $this->expectException(QueryException::class);

        JournalEntrySource::create([
            'organization_id' => $this->orgA->id,
            'journal_entry_id' => $draft->id,
            'source_type' => 'business_transaction',
            'source_id' => $tx->id,
            'business_transaction_id' => $tx->id,
            'leg_role' => 'secondary',
        ]);
    }

    public function test_journal_entry_foreign_key_integrity(): void
    {
        $tx = $this->createBusinessTransaction();

        $this->expectException(QueryException::class);
        // Link to nonexistent journal_entry_id = 999999
        JournalEntrySource::create([
            'organization_id' => $this->orgA->id,
            'journal_entry_id' => 999999,
            'source_type' => 'business_transaction',
            'source_id' => $tx->id,
            'business_transaction_id' => $tx->id,
            'leg_role' => 'primary',
        ]);
    }

    public function test_business_transaction_foreign_key_integrity(): void
    {
        $tx = $this->createBusinessTransaction();
        $draft = $this->generator->generate($tx, $this->makerA);

        // Delete the source link first to avoid unique constraint
        JournalEntrySource::where('source_id', $tx->id)->delete();

        $this->expectException(QueryException::class);
        // Link to nonexistent business_transaction_id = 999999
        JournalEntrySource::create([
            'organization_id' => $this->orgA->id,
            'journal_entry_id' => $draft->id,
            'source_type' => 'business_transaction',
            'source_id' => 999999,
            'business_transaction_id' => 999999,
            'leg_role' => 'primary',
        ]);
    }

    public function test_atomic_rollback_on_failure_leaves_no_orphaned_journals(): void
    {
        $tx = $this->createBusinessTransaction();

        $initialJournals = JournalEntry::count();
        $initialSources = JournalEntrySource::count();

        // Simulate a failure during generation by passing an invalid custom line configuration
        try {
            DB::transaction(function () use ($tx) {
                // Generate draft
                $draft = $this->generator->generate($tx, $this->makerA);

                // Simulate unexpected failure inside transaction
                throw new \RuntimeException('Simulated catastrophic failure after draft creation');
            });
        } catch (\RuntimeException $e) {
            // Caught
        }

        // Entire transaction must be rolled back!
        $this->assertSame($initialJournals, JournalEntry::count());
        $this->assertSame($initialSources, JournalEntrySource::count());
        $this->assertDatabaseMissing('journal_entry_sources', ['source_id' => $tx->id]);
    }
}
