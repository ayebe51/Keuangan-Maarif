<?php

namespace Tests\Feature;

use App\Domain\Accounting\Exceptions\AlreadyReversedException;
use App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domain\Accounting\Exceptions\ImmutableJournalException;
use App\Domain\Accounting\Exceptions\SegregationOfDutiesException;
use App\Domain\Accounting\Exceptions\UnbalancedJournalException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Services\FiscalPeriodService;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JournalPostingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Organization $orgOther;
    private User $maker;
    private User $checker;
    private JournalPostingService $postingService;
    private Account $accKas;
    private Account $accBeban;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\CoaSeeder::class);

        $this->org = Organization::where('code', 'LP-MAARIF-CLP')->first();
        $this->orgOther = Organization::create([
            'code' => 'ORG-OTHER',
            'name' => 'Organisasi Lain',
            'type' => 'madrasah',
            'is_active' => true,
        ]);

        TenantContext::setTenantId($this->org->id);

        $this->maker = User::where('email', 'operator@maarif-cilacap.org')->first();
        $this->checker = User::where('email', 'admin@maarif-cilacap.org')->first();

        $this->postingService = app(JournalPostingService::class);

        $this->accKas = Account::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', '1110')->first();
        $this->accBeban = Account::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', '5100')->first();
    }

    public function test_balanced_journal_posts_successfully(): void
    {
        $draft = $this->postingService->createDraft([
            'organization_id' => $this->org->id,
            'entry_date' => '2026-03-15',
            'description' => 'Pembayaran Beban Operasional Kantor',
            'lines' => [
                ['account_id' => $this->accBeban->id, 'debit' => '750000.00', 'credit' => '0.00'],
                ['account_id' => $this->accKas->id, 'debit' => '0.00', 'credit' => '750000.00'],
            ],
        ], $this->maker);

        $this->assertTrue($draft->isDraft());
        $this->assertSame(2, $draft->lines()->count());

        // Checker posts the draft
        $posted = $this->postingService->post($draft, $this->checker);

        $this->assertTrue($posted->isPosted());
        $this->assertNotNull($posted->posted_at);
        $this->assertSame($this->checker->id, $posted->posted_by);
    }

    public function test_unbalanced_journal_throws_exception_and_rolls_back(): void
    {
        // AT-002: Debit 100.00 vs Credit 99.99
        $draft = $this->postingService->createDraft([
            'organization_id' => $this->org->id,
            'entry_date' => '2026-03-15',
            'description' => 'Unbalanced test',
            'lines' => [
                ['account_id' => $this->accBeban->id, 'debit' => '100.00', 'credit' => '0.00'],
                ['account_id' => $this->accKas->id, 'debit' => '0.00', 'credit' => '99.99'],
            ],
        ], $this->maker);

        $this->expectException(UnbalancedJournalException::class);
        $this->postingService->post($draft, $this->checker);
    }

    public function test_maker_cannot_post_own_journal_segregation_of_duties(): void
    {
        // AT-005: SoD violation
        $draft = $this->postingService->createDraft([
            'organization_id' => $this->org->id,
            'entry_date' => '2026-03-15',
            'description' => 'Maker self-approval attempt',
            'lines' => [
                ['account_id' => $this->accBeban->id, 'debit' => '50000.00', 'credit' => '0.00'],
                ['account_id' => $this->accKas->id, 'debit' => '0.00', 'credit' => '50000.00'],
            ],
        ], $this->maker);

        $this->expectException(SegregationOfDutiesException::class);
        $this->postingService->post($draft, $this->maker);
    }

    public function test_posted_journal_is_immutable_rejects_update_and_delete(): void
    {
        // AT-008: Posted immutability
        $draft = $this->postingService->createDraft([
            'organization_id' => $this->org->id,
            'entry_date' => '2026-03-15',
            'description' => 'Legitimate transaction',
            'lines' => [
                ['account_id' => $this->accBeban->id, 'debit' => '10000.00', 'credit' => '0.00'],
                ['account_id' => $this->accKas->id, 'debit' => '0.00', 'credit' => '10000.00'],
            ],
        ], $this->maker);

        $posted = $this->postingService->post($draft, $this->checker);

        // Attempt direct update of description
        try {
            $posted->description = 'Tampered description';
            $posted->save();
            $this->fail('Expected ImmutableJournalException when updating description');
        } catch (ImmutableJournalException $e) {
            $this->assertStringContainsString('Cannot modify journal entry', $e->getMessage());
        }

        // Attempt direct deletion
        try {
            $posted->delete();
            $this->fail('Expected ImmutableJournalException when deleting posted journal');
        } catch (ImmutableJournalException $e) {
            $this->assertStringContainsString('Cannot delete journal entry', $e->getMessage());
        }

        // Attempt direct line modification
        $line = $posted->lines()->first();
        try {
            $line->debit = '99999.00';
            $line->save();
            $this->fail('Expected ImmutableJournalException when modifying line of posted journal');
        } catch (ImmutableJournalException $e) {
            $this->assertStringContainsString('lines of', $e->getMessage());
        }
    }

    public function test_reversal_creates_inverted_journal_and_marks_original_as_reversed(): void
    {
        // AT-009: Reversal
        $draft = $this->postingService->createDraft([
            'organization_id' => $this->org->id,
            'entry_date' => '2026-03-15',
            'description' => 'Original transaction to reverse',
            'lines' => [
                ['account_id' => $this->accBeban->id, 'debit' => '250000.00', 'credit' => '0.00'],
                ['account_id' => $this->accKas->id, 'debit' => '0.00', 'credit' => '250000.00'],
            ],
        ], $this->maker);

        $posted = $this->postingService->post($draft, $this->checker);

        $reversal = $this->postingService->reverse(
            originalEntry: $posted,
            user: $this->checker,
            reversalDate: '2026-03-20',
            reason: 'Salah pencatatan beban operasional'
        );

        $this->assertTrue($reversal->isPosted());
        $this->assertSame(JournalEntry::TYPE_REVERSAL, $reversal->entry_type);
        $this->assertSame($posted->id, $reversal->reversed_entry_id);

        // Check mirrored lines
        $reversalLines = $reversal->lines()->orderBy('line_number')->get();
        $this->assertSame('0.00', $reversalLines[0]->debit);
        $this->assertSame('250000.00', $reversalLines[0]->credit);

        $this->assertSame('250000.00', $reversalLines[1]->debit);
        $this->assertSame('0.00', $reversalLines[1]->credit);

        // Check original entry is updated to REVERSED and linked
        $posted->refresh();
        $this->assertTrue($posted->isReversed());
        $this->assertSame($reversal->id, $posted->reversed_entry_id);
    }

    public function test_double_reversal_throws_already_reversed_exception(): void
    {
        // AT-012: Double reversal prevention
        $draft = $this->postingService->createDraft([
            'organization_id' => $this->org->id,
            'entry_date' => '2026-03-15',
            'description' => 'Entry for double reversal test',
            'lines' => [
                ['account_id' => $this->accBeban->id, 'debit' => '10000.00', 'credit' => '0.00'],
                ['account_id' => $this->accKas->id, 'debit' => '0.00', 'credit' => '10000.00'],
            ],
        ], $this->maker);

        $posted = $this->postingService->post($draft, $this->checker);
        $this->postingService->reverse($posted, $this->checker);

        $this->expectException(AlreadyReversedException::class);
        $this->postingService->reverse($posted, $this->checker);
    }

    public function test_reversal_into_closed_period_throws_exception(): void
    {
        // AT-011: Explicit reversal into closed period rejected
        $draft = $this->postingService->createDraft([
            'organization_id' => $this->org->id,
            'entry_date' => '2026-03-15',
            'description' => 'Entry to reverse',
            'lines' => [
                ['account_id' => $this->accBeban->id, 'debit' => '10000.00', 'credit' => '0.00'],
                ['account_id' => $this->accKas->id, 'debit' => '0.00', 'credit' => '10000.00'],
            ],
        ], $this->maker);

        $posted = $this->postingService->post($draft, $this->checker);

        $this->expectException(ClosedFiscalPeriodException::class);
        $this->postingService->reverse(
            originalEntry: $posted,
            user: $this->checker,
            reversalDate: '2025-01-01' // Closed / undefined period
        );
    }

    public function test_cross_tenant_dimension_throws_cross_tenant_violation(): void
    {
        // AT-007: Cross tenant dimension check
        TenantContext::setTenantId($this->orgOther->id);
        $accOther = Account::create([
            'organization_id' => $this->orgOther->id,
            'account_type' => Account::TYPE_EXPENSE,
            'normal_balance' => Account::BALANCE_DEBIT,
            'code' => '5999',
            'name' => 'Beban Org Lain',
            'is_postable' => true,
        ]);
        TenantContext::setTenantId($this->org->id);

        $this->expectException(CrossTenantViolationException::class);
        $this->postingService->createDraft([
            'organization_id' => $this->org->id,
            'entry_date' => '2026-03-15',
            'description' => 'Cross tenant injection attempt',
            'lines' => [
                ['account_id' => $accOther->id, 'debit' => '10000.00', 'credit' => '0.00'],
                ['account_id' => $this->accKas->id, 'debit' => '0.00', 'credit' => '10000.00'],
            ],
        ], $this->maker);
    }
}
