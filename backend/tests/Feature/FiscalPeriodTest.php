<?php

namespace Tests\Feature;

use App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Services\FiscalPeriodService;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use InvalidArgumentException;
use Tests\TestCase;

class FiscalPeriodTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Organization $org;
    private User $admin;
    private FiscalPeriodService $periodService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\CoaSeeder::class);

        $this->org = Organization::where('code', 'LP-MAARIF-CLP')->first();
        $this->admin = User::where('email', 'admin@maarif-cilacap.org')->first();
        $this->periodService = app(FiscalPeriodService::class);
    }

    public function test_period_lifecycle_open_close_reopen(): void
    {
        $period = FiscalPeriod::where('organization_id', $this->org->id)->where('code', '2026-03')->first();
        $this->assertTrue($period->isOpen());

        // Close period
        $closed = $this->periodService->closePeriod($period, $this->admin);
        $this->assertTrue($closed->isClosed());
        $this->assertFalse($closed->isOpen());
        $this->assertSame($this->admin->id, $closed->closed_by);

        // Reopen without reason should fail
        $this->expectException(InvalidArgumentException::class);
        $this->periodService->reopenPeriod($closed, $this->admin, '');
    }

    public function test_reopen_period_with_audit_reason_succeeds(): void
    {
        $period = FiscalPeriod::where('organization_id', $this->org->id)->where('code', '2026-03')->first();
        $this->periodService->closePeriod($period, $this->admin);

        $reopened = $this->periodService->reopenPeriod($period, $this->admin, 'Audit adjustment approved by Lead Auditor');
        $this->assertTrue($reopened->isOpen());
        $this->assertNull($reopened->closed_by);
    }

    public function test_ensure_date_in_open_period_succeeds_for_valid_date(): void
    {
        $period = $this->periodService->ensureDateInOpenPeriod('2026-03-15', $this->org->id);
        $this->assertSame('2026-03', $period->code);
    }

    public function test_ensure_date_in_closed_period_throws_exception(): void
    {
        // AT-004: Post transaction on closed period throws ClosedFiscalPeriodException
        $period = FiscalPeriod::where('organization_id', $this->org->id)->where('code', '2026-03')->first();
        $this->periodService->closePeriod($period, $this->admin);

        $this->expectException(ClosedFiscalPeriodException::class);
        $this->periodService->ensureDateInOpenPeriod('2026-03-15', $this->org->id);
    }

    public function test_undefined_period_date_throws_exception(): void
    {
        $this->expectException(ClosedFiscalPeriodException::class);
        $this->periodService->ensureDateInOpenPeriod('2025-01-01', $this->org->id);
    }
}
