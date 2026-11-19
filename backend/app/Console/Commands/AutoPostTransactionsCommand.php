<?php

namespace App\Console\Commands;

use App\Domain\Accounting\Services\AutoPostExecutionCoordinator;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use Illuminate\Console\Command;

class AutoPostTransactionsCommand extends Command
{
    protected $signature = 'accounting:auto-post 
                            {--tenant= : Specific organization/tenant ID to execute}
                            {--limit=50 : Maximum number of candidate transactions to process per tenant}';

    protected $description = 'Executes controlled deterministic auto-post for eligible classified transactions';

    public function handle(AutoPostExecutionCoordinator $coordinator): int
    {
        $tenantId = $this->option('tenant');
        $limit = (int) $this->option('limit');

        if ($tenantId) {
            $orgs = Organization::where('id', $tenantId)->where('is_active', true)->get();
            if ($orgs->isEmpty()) {
                $this->error("Organization with ID #{$tenantId} not found or is inactive.");

                return self::FAILURE;
            }
        } else {
            $orgs = Organization::where('is_active', true)->get();
        }

        $this->info('Starting deterministic auto-post batch processing...');

        foreach ($orgs as $org) {
            TenantContext::setTenantId($org->id);
            $this->line("Processing organization: [{$org->code}] {$org->name} (ID: {$org->id})...");

            $telemetry = $coordinator->executeBatch($org->id, $limit);

            $this->table(
                ['Organization', 'Total Candidates', 'Processed', 'Success', 'Skipped', 'Failed'],
                [[
                    $org->code,
                    $telemetry['total_candidates'],
                    $telemetry['processed_count'],
                    $telemetry['success_count'],
                    $telemetry['skipped_count'],
                    $telemetry['failed_count'],
                ]]
            );
        }

        TenantContext::setTenantId(null);
        $this->info('Auto-post batch processing completed.');

        return self::SUCCESS;
    }
}
