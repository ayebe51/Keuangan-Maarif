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

            $period = $this->fiscalPeriodService->ensureDateInOpenPeriod(
                $data['effective_date'] ?? now()->format('Y-m-d'),
                $orgId
            );

            $allocated = Money::of($data['allocated_amount']);
            if (!$allocated->isPositive()) {
                throw new InvalidArgumentException("Allocated amount must be greater than zero.");
            }

            $allocation = FundAllocation::create([
                'organization_id' => $orgId,
                'fund_id' => $fund->id,
                'fiscal_period_id' => $data['fiscal_period_id'] ?? $period->id,
                'description' => $data['description'] ?? null,
                'allocated_amount' => $allocated->getAmount(),
                'committed_amount' => '0.00',
                'disbursed_amount' => '0.00',
                'returned_amount' => '0.00',
                'status' => $data['status'] ?? FundAllocation::STATUS_ACTIVE,
            ]);

            if ($user) {
                AuditService::log(
                    action: 'FUND_ALLOCATION_CREATED',
                    modelType: FundAllocation::class,
                    modelId: $allocation->id,
                    newValues: $allocation->toArray(),
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $allocation;
        });
    }

    public function commit(FundAllocation $allocation, float|string $amount, ?User $user = null): FundAllocation
    {
        return DB::transaction(function () use ($allocation, $amount, $user) {
            $fund = $allocation->fund;
            $amt = Money::of($amount);
            if (!$amt->isPositive()) {
                throw new InvalidArgumentException("Commit amount must be greater than zero.");
            }

            $available = Money::of($allocation->available_amount);
            if ($amt->isGreaterThan($available)) {
                throw new NegativeFundBalanceException(
                    fundCode: $fund->code,
                    attemptedAmount: $amt->getAmount(),
                    availableAmount: $available->getAmount()
                );
            }

            $before = $allocation->toArray();
            $newCommitted = Money::of($allocation->committed_amount)->add($amt);
            $allocation->committed_amount = $newCommitted->getAmount();
            $allocation->save();

            if ($user) {
                AuditService::log(
                    action: 'FUND_COMMITTED',
                    modelType: FundAllocation::class,
                    modelId: $allocation->id,
                    oldValues: $before,
                    newValues: $allocation->fresh()->toArray(),
                    userId: $user->id,
                    organizationId: $allocation->organization_id
                );
            }

            return $allocation;
        });
    }

    public function disburse(
        FundAllocation $allocation,
        float|string $amount,
        bool $fromCommitted = false,
        ?User $user = null
    ): FundAllocation {
        return DB::transaction(function () use ($allocation, $amount, $fromCommitted, $user) {
            $fund = $allocation->fund;
            $amt = Money::of($amount);
            if (!$amt->isPositive()) {
                throw new InvalidArgumentException("Disburse amount must be greater than zero.");
            }

            if ($fromCommitted) {
                $committed = Money::of($allocation->committed_amount);
                if ($amt->isGreaterThan($committed)) {
                    throw new NegativeFundBalanceException(
                        fundCode: $fund->code,
                        attemptedAmount: $amt->getAmount(),
                        availableAmount: $committed->getAmount(),
                        message: "Cannot disburse {$amt->getAmount()} from committed balance. Committed is only {$committed->getAmount()}."
                    );
                }
                $newCommitted = $committed->subtract($amt);
                $allocation->committed_amount = $newCommitted->getAmount();
            } else {
                $available = Money::of($allocation->available_amount);
                if ($amt->isGreaterThan($available)) {
                    throw new NegativeFundBalanceException(
                        fundCode: $fund->code,
                        attemptedAmount: $amt->getAmount(),
                        availableAmount: $available->getAmount()
                    );
                }
            }

            $before = $allocation->toArray();
            $newDisbursed = Money::of($allocation->disbursed_amount)->add($amt);
            $allocation->disbursed_amount = $newDisbursed->getAmount();
            $allocation->save();

            if ($user) {
                AuditService::log(
                    action: 'FUND_DISBURSED',
                    modelType: FundAllocation::class,
                    modelId: $allocation->id,
                    oldValues: $before,
                    newValues: $allocation->fresh()->toArray(),
                    userId: $user->id,
                    organizationId: $allocation->organization_id
                );
            }

            return $allocation;
        });
    }

    public function returnFunds(FundAllocation $allocation, float|string $amount, ?User $user = null): FundAllocation
    {
        return DB::transaction(function () use ($allocation, $amount, $user) {
            $amt = Money::of($amount);
            if (!$amt->isPositive()) {
                throw new InvalidArgumentException("Return amount must be greater than zero.");
            }

            $before = $allocation->toArray();
            $newReturned = Money::of($allocation->returned_amount)->add($amt);
            $allocation->returned_amount = $newReturned->getAmount();
            $allocation->save();

            if ($user) {
                AuditService::log(
                    action: 'FUND_RETURNED',
                    modelType: FundAllocation::class,
                    modelId: $allocation->id,
                    oldValues: $before,
                    newValues: $allocation->fresh()->toArray(),
                    userId: $user->id,
                    organizationId: $allocation->organization_id
                );
            }

            return $allocation;
        });
    }

    public function getSummary(Fund $fund): array
    {
        $allocations = $fund->allocations;

        $totalAllocated = Money::zero();
        $totalCommitted = Money::zero();
        $totalDisbursed = Money::zero();
        $totalReturned = Money::zero();

        foreach ($allocations as $alloc) {
            $totalAllocated = $totalAllocated->add(Money::of($alloc->allocated_amount ?? 0));
            $totalCommitted = $totalCommitted->add(Money::of($alloc->committed_amount ?? 0));
            $totalDisbursed = $totalDisbursed->add(Money::of($alloc->disbursed_amount ?? 0));
            $totalReturned = $totalReturned->add(Money::of($alloc->returned_amount ?? 0));
        }

        $totalAvailable = $totalAllocated
            ->subtract($totalCommitted)
            ->subtract($totalDisbursed)
            ->add($totalReturned);

        return [
            'fund_id' => $fund->id,
            'code' => $fund->code,
            'name' => $fund->name,
            'budget_amount' => $fund->budget_amount,
            'total_allocated' => $totalAllocated->getAmount(),
            'total_committed' => $totalCommitted->getAmount(),
            'total_disbursed' => $totalDisbursed->getAmount(),
            'total_returned' => $totalReturned->getAmount(),
            'total_available' => $totalAvailable->getAmount(),
        ];
    }
}
