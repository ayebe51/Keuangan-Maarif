<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FiscalPeriodService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    public function createPeriod(array $data, ?User $user = null): FiscalPeriod
    {
        return DB::transaction(function () use ($data, $user) {
            if (! empty($data['is_current'])) {
                // If setting as current, unset previous current for this tenant
                FiscalPeriod::where('organization_id', $data['organization_id'])
                    ->where('is_current', true)
                    ->update(['is_current' => false]);
            }

            $period = FiscalPeriod::create([
                'organization_id' => $data['organization_id'],
                'code' => $data['code'],
                'name' => $data['name'],
                'period_type' => $data['period_type'] ?? 'monthly',
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'status' => $data['status'] ?? FiscalPeriod::STATUS_OPEN,
                'is_current' => $data['is_current'] ?? false,
            ]);

            if ($user) {
                AuditService::log(
                    action: 'PERIOD_CREATED',
                    modelType: FiscalPeriod::class,
                    modelId: $period->id,
                    newValues: $period->toArray(),
                    userId: $user->id,
                    organizationId: $period->organization_id
                );
            }

            return $period;
        });
    }

    public function closePeriod(FiscalPeriod $period, User $user): FiscalPeriod
    {
        return DB::transaction(function () use ($period, $user) {
            $lockedPeriod = FiscalPeriod::withoutGlobalScopes()
                ->where('id', $period->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = $lockedPeriod->toArray();

            $lockedPeriod->status = FiscalPeriod::STATUS_CLOSED;
            $lockedPeriod->closed_at = now();
            $lockedPeriod->closed_by = $user->id;
            $lockedPeriod->save();

            // Lock all business transactions in this period and organization
            BusinessTransaction::withoutGlobalScopes()
                ->where('organization_id', $lockedPeriod->organization_id)
                ->where(function ($q) use ($lockedPeriod) {
                    $q->where('fiscal_period_id', $lockedPeriod->id)
                        ->orWhereBetween('transaction_date', [
                            $lockedPeriod->start_date->format('Y-m-d'),
                            $lockedPeriod->end_date->format('Y-m-d'),
                        ]);
                })
                ->update([
                    'is_period_locked' => true,
                    'fiscal_period_id' => $lockedPeriod->id,
                ]);

            AuditService::log(
                action: 'PERIOD_CLOSED',
                modelType: FiscalPeriod::class,
                modelId: $lockedPeriod->id,
                oldValues: $before,
                newValues: $lockedPeriod->toArray(),
                userId: $user->id,
                organizationId: $lockedPeriod->organization_id
            );

            return $lockedPeriod;
        });
    }

    public function softClosePeriod(FiscalPeriod $period, User $user): FiscalPeriod
    {
        return DB::transaction(function () use ($period, $user) {
            $lockedPeriod = FiscalPeriod::withoutGlobalScopes()
                ->where('id', $period->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = $lockedPeriod->toArray();

            $lockedPeriod->status = FiscalPeriod::STATUS_SOFT_CLOSE;
            $lockedPeriod->save();

            AuditService::log(
                action: 'PERIOD_SOFT_CLOSED',
                modelType: FiscalPeriod::class,
                modelId: $lockedPeriod->id,
                oldValues: $before,
                newValues: $lockedPeriod->toArray(),
                userId: $user->id,
                organizationId: $lockedPeriod->organization_id
            );

            return $lockedPeriod;
        });
    }

    public function reopenPeriod(FiscalPeriod $period, User $user, string $reason): FiscalPeriod
    {
        if (empty(trim($reason))) {
            throw new InvalidArgumentException('Reopening a closed fiscal period requires an explicit audit reason.');
        }

        return DB::transaction(function () use ($period, $user, $reason) {
            $lockedPeriod = FiscalPeriod::withoutGlobalScopes()
                ->where('id', $period->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = $lockedPeriod->toArray();

            $lockedPeriod->status = FiscalPeriod::STATUS_OPEN;
            $lockedPeriod->closed_at = null;
            $lockedPeriod->closed_by = null;
            $lockedPeriod->save();

            // Unlock business transactions in this period and organization
            BusinessTransaction::withoutGlobalScopes()
                ->where('organization_id', $lockedPeriod->organization_id)
                ->where('fiscal_period_id', $lockedPeriod->id)
                ->update(['is_period_locked' => false]);

            AuditService::log(
                action: 'PERIOD_REOPENED',
                modelType: FiscalPeriod::class,
                modelId: $lockedPeriod->id,
                oldValues: $before,
                newValues: array_merge($lockedPeriod->toArray(), ['reopen_reason' => $reason]),
                notes: $reason,
                userId: $user->id,
                organizationId: $lockedPeriod->organization_id
            );

            return $lockedPeriod;
        });
    }

    public function ensureDateInOpenPeriod(string $date, int $organizationId, bool $lockForUpdate = false): FiscalPeriod
    {
        $query = FiscalPeriod::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->forDate($date);

        if ($lockForUpdate && DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $period = $query->first();

        if (! $period) {
            throw new ClosedFiscalPeriodException($date, 'undefined', "No fiscal period defined for transaction date '{$date}'.");
        }

        if (! $period->isOpen()) {
            throw new ClosedFiscalPeriodException($date, $period->status);
        }

        return $period;
    }
}
