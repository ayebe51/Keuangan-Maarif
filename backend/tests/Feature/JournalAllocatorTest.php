<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\JournalNumberAllocator;
use App\Domain\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JournalAllocatorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private JournalNumberAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\CoaSeeder::class);

        $this->org = Organization::where('code', 'LP-MAARIF-CLP')->first();
        $this->allocator = app(JournalNumberAllocator::class);
    }

    public function test_allocator_generates_sequential_numbers(): void
    {
        // AT-006: Sequential journal allocation
        $num1 = $this->allocator->allocate($this->org->id, 2026);
        $this->assertSame('JE-2026-00001', $num1);

        // Simulate creation in DB
        DB::table('journal_entries')->insert([
            'organization_id' => $this->org->id,
            'fiscal_period_id' => 1,
            'entry_number' => $num1,
            'entry_date' => '2026-03-01',
            'entry_type' => 'payment',
            'description' => 'Test entry 1',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $num2 = $this->allocator->allocate($this->org->id, 2026);
        $this->assertSame('JE-2026-00002', $num2);
    }

    public function test_allocator_handles_multiple_sequential_iterations(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $num = $this->allocator->allocate($this->org->id, 2026);
            $expected = sprintf('JE-2026-%05d', $i);
            $this->assertSame($expected, $num);

            DB::table('journal_entries')->insert([
                'organization_id' => $this->org->id,
                'fiscal_period_id' => 1,
                'entry_number' => $num,
                'entry_date' => '2026-03-01',
                'entry_type' => 'payment',
                'description' => "Test entry {$i}",
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(10, JournalEntry::where('organization_id', $this->org->id)->count());
    }
}
