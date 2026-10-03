<?php

namespace App\Domain\Fund\Services;

use App\Domain\Accounting\Services\AccountService;
use App\Domain\Accounting\Services\FiscalPeriodService;
use App\Domain\Accounting\ValueObjects\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Fund\Exceptions\NegativeFundBalanceException;
use App\Domain\Fund\Models\Fund;
use App\Domain\Fund\Models\FundAllocation;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FundService
{
    public function __construct(
        protected AccountService $accountService,
        protected FiscalPeriodService $fiscalPeriodService
    ) {}

    public function createFund(array $data, ?User $user = null): Fund
    {
        return DB::transaction(function () use ($data, $user) {
            $orgId = (int) $data['organization_id'];

            $type = $data['fund_type'] ?? Fund::TYPE_RESTRICTED;
            if (!in_array($type, Fund::ALL_TYPES, true)) {
                throw new InvalidArgumentException("Invalid fund type '{$type}'.");
            }

            $status = $data['status'] ?? Fund::STATUS_ACTIVE;
            if (!in_array($status, Fund::ALL_STATUSES, true)) {
                throw new InvalidArgumentException("Invalid fund status '{$status}'.");
            }

            $accountId = $data['account_id'] ?? null;
            if ($accountId) {
                $this->accountService->ensurePostable($accountId, $orgId);
            }

            $budget = Money::of($data['budget_amount'] ?? 0);

            $fund = Fund::create([
                'organization_id' => $orgId,
                'code' => $data['code'],
                'name' => $data['name'],
                'fund_type' => $type,
                'account_id' => $accountId,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'budget_amount' => $budget->getAmount(),
                'description' => $data['description'] ?? null,
                'status' => $status,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if ($user) {
                AuditService::log(
                    action: 'FUND_CREATED',
                    modelType: Fund::class,
                    modelId: $fund->id,
                    newValues: $fund->toArray(),
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $fund;
        });
    }

    public function updateFund(Fund $fund, array $data, ?User $user = null): Fund
    {
        return DB::transaction(function () use ($fund, $data, $user) {
            $orgId = (int) $fund->organization_id;

            if (array_key_exists('organization_id', $data) && (int) $data['organization_id'] !== $orgId) {
                throw new CrossTenantViolationException("Cannot change organization of fund.");
            }

            if (isset($data['fund_type']) && !in_array($data['fund_type'], Fund::ALL_TYPES, true)) {
                throw new InvalidArgumentException("Invalid fund type '{$data['fund_type']}'.");
            }

            if (isset($data['status']) && !in_array($data['status'], Fund::ALL_STATUSES, true)) {
                throw new InvalidArgumentException("Invalid fund status '{$data['status']}'.");
            }

            if (array_key_exists('account_id', $data) && $data['account_id'] !== null) {
                $this->accountService->ensurePostable($data['account_id'], $orgId);
            }

            if (isset($data['budget_amount'])) {
                $data['budget_amount'] = Money::of($data['budget_amount'])->getAmount();
            }

            $before = $fund->toArray();
            $fund->update($data);

            if ($user) {
                AuditService::log(
                    action: 'FUND_UPDATED',
                    modelType: Fund::class,
                    modelId: $fund->id,
                    oldValues: $before,
                    newValues: $fund->fresh()->toArray(),
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $fund;
        });
    }

    public function deleteFund(Fund $fund, ?User $user = null): void
    {
        DB::transaction(function () use ($fund, $user) {
            $orgId = (int) $fund->organization_id;
            $before = $fund->toArray();

            $fund->delete();

            if ($user) {
                AuditService::log(
                    action: 'FUND_DELETED',
                    modelType: Fund::class,
                    modelId: $fund->id,
                    oldValues: $before,
                    userId: $user->id,
                    organizationId: $orgId
                );
            }
        });
    }

    public function createAllocation(array $data, ?User $user = null): FundAllocation
    {
        return DB::transaction(function () use ($data, $user) {
            $orgId = (int) $data['organization_id'];

            $fund = Fund::withoutGlobalScopes()->where('id', $data['fund_id'])->first();
            if (!$fund || (int) $fund->organization_id !== $orgId) {
                throw new CrossTenantViolationException("Fund not found or belongs to a different organization.");
            }

            $effectiveDate = $data['effective_date'] ?? null;
            if (!$effectiveDate && isset($data['fiscal_period_id'])) {
                $fp = \App\Domain\Accounting\Models\FiscalPeriod::find($data['fiscal_period_id']);
                if ($fp) {
                    $effectiveDate = $fp->start_date->format('Y-m-d');
                }
            }
            if (!$effectiveDate) {
                $effectiveDate = now()->format('Y-m-d');
            }

            $period = $this->fiscalPeriodService->ensureDateInOpenPeriod(
