<?php

namespace App\Domain\Classification\Services;

use App\Domain\Accounting\Services\AccountService;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Classification\Models\TransactionCategory;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TransactionCategoryService
{
    public function __construct(
        protected AccountService $accountService
    ) {}

    public function create(array $data, ?User $user = null): TransactionCategory
    {
        return DB::transaction(function () use ($data, $user) {
            $orgId = (int) $data['organization_id'];

            $direction = strtoupper($data['direction'] ?? '');
            if (!in_array($direction, TransactionCategory::ALL_DIRECTIONS, true)) {
                throw new InvalidArgumentException("Invalid transaction category direction '{$direction}'. Must be IN, OUT, or TRANSFER.");
            }

            $debitAccId = $data['default_debit_account_id'] ?? null;
            if ($debitAccId) {
                $this->accountService->ensurePostable($debitAccId, $orgId);
            }

            $creditAccId = $data['default_credit_account_id'] ?? null;
            if ($creditAccId) {
                $this->accountService->ensurePostable($creditAccId, $orgId);
            }

            $category = TransactionCategory::create([
                'organization_id' => $orgId,
                'code' => $data['code'],
                'name' => $data['name'],
                'direction' => $direction,
                'default_debit_account_id' => $debitAccId,
                'default_credit_account_id' => $creditAccId,
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if ($user) {
                AuditService::log(
                    action: 'TRANSACTION_CATEGORY_CREATED',
                    modelType: TransactionCategory::class,
                    modelId: $category->id,
                    newValues: $category->toArray(),
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $category;
        });
    }

    public function update(TransactionCategory $category, array $data, ?User $user = null): TransactionCategory
    {
        return DB::transaction(function () use ($category, $data, $user) {
            $orgId = (int) $category->organization_id;

            if (array_key_exists('organization_id', $data) && (int) $data['organization_id'] !== $orgId) {
                throw new CrossTenantViolationException("Cannot change organization of transaction category.");
            }

            if (isset($data['direction'])) {
                $data['direction'] = strtoupper($data['direction']);
                if (!in_array($data['direction'], TransactionCategory::ALL_DIRECTIONS, true)) {
                    throw new InvalidArgumentException("Invalid transaction category direction '{$data['direction']}'.");
                }
            }

            if (array_key_exists('default_debit_account_id', $data) && $data['default_debit_account_id'] !== null) {
                $this->accountService->ensurePostable($data['default_debit_account_id'], $orgId);
            }

            if (array_key_exists('default_credit_account_id', $data) && $data['default_credit_account_id'] !== null) {
                $this->accountService->ensurePostable($data['default_credit_account_id'], $orgId);
            }

            $before = $category->toArray();
            $category->update($data);

            if ($user) {
                AuditService::log(
                    action: 'TRANSACTION_CATEGORY_UPDATED',
                    modelType: TransactionCategory::class,
                    modelId: $category->id,
                    oldValues: $before,
                    newValues: $category->fresh()->toArray(),
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $category;
        });
    }

    public function delete(TransactionCategory $category, ?User $user = null): void
    {
        DB::transaction(function () use ($category, $user) {
            $orgId = (int) $category->organization_id;
            $before = $category->toArray();

            $category->delete();

            if ($user) {
                AuditService::log(
                    action: 'TRANSACTION_CATEGORY_DELETED',
                    modelType: TransactionCategory::class,
                    modelId: $category->id,
                    oldValues: $before,
                    userId: $user->id,
                    organizationId: $orgId
                );
            }
        });
    }
}
