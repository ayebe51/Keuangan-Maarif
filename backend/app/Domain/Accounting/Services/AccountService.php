<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Exceptions\NonPostableAccountException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccountService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    public function getTree(int $organizationId): array
    {
        $accounts = Account::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        $grouped = [];
        foreach ($accounts as $acc) {
            $grouped[$acc->parent_id ?? 0][] = $acc;
        }

        return $this->buildBranch($grouped, 0);
    }

    private function buildBranch(array &$grouped, int $parentId): array
    {
        $branch = [];
        if (!isset($grouped[$parentId])) {
            return $branch;
        }

        foreach ($grouped[$parentId] as $node) {
            $item = $node->toArray();
            $children = $this->buildBranch($grouped, $node->id);
            if (!empty($children)) {
                $item['children'] = $children;
            } else {
                $item['children'] = [];
            }
            $branch[] = $item;
        }

        return $branch;
    }

    public function createAccount(array $data, ?User $user = null): Account
    {
        return DB::transaction(function () use ($data, $user) {
            $orgId = (int) $data['organization_id'];

            if (!empty($data['parent_id'])) {
                $parent = Account::withoutGlobalScopes()->find($data['parent_id']);
                if ($parent && (int)$parent->organization_id !== $orgId) {
                    throw new CrossTenantViolationException("Parent account belongs to a different organization.");
                }
            }

            // Normal balance default
            if (empty($data['normal_balance'])) {
                $type = $data['account_type'] ?? Account::TYPE_ASSET;
                $data['normal_balance'] = in_array($type, [Account::TYPE_ASSET, Account::TYPE_EXPENSE], true)
                    ? Account::BALANCE_DEBIT
                    : Account::BALANCE_CREDIT;
            }

            $account = Account::create([
                'organization_id' => $orgId,
                'parent_id' => $data['parent_id'] ?? null,
                'account_type' => $data['account_type'],
                'normal_balance' => $data['normal_balance'],
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_postable' => $data['is_postable'] ?? true,
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            if ($user) {
                AuditService::log(
                    action: 'ACCOUNT_CREATED',
                    modelType: Account::class,
                    modelId: $account->id,
                    newValues: $account->toArray(),
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $account;
        });
    }

    public function updateAccount(Account $account, array $data, ?User $user = null): Account
    {
        return DB::transaction(function () use ($account, $data, $user) {
            $orgId = (int) $account->organization_id;

            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
                $parentId = (int) $data['parent_id'];
                if ($parentId === (int)$account->id) {
                    throw new InvalidArgumentException("Account cannot be its own parent.");
                }

                // Check descendants to prevent cycles
                $descendantIds = $this->getDescendantIds($account->id);
                if (in_array($parentId, $descendantIds, true)) {
                    throw new InvalidArgumentException("Cannot set parent to an account that is already a descendant (circular hierarchy).");
                }

                $parent = Account::withoutGlobalScopes()->find($parentId);
                if ($parent && (int)$parent->organization_id !== $orgId) {
                    throw new CrossTenantViolationException("Parent account belongs to a different organization.");
                }
            }

            $before = $account->toArray();
            $account->update($data);

            if ($user) {
                AuditService::log(
                    action: 'ACCOUNT_UPDATED',
                    modelType: Account::class,
                    modelId: $account->id,
                    oldValues: $before,
                    newValues: $account->fresh()->toArray(),
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $account;
        });
    }

    public function ensurePostable(int $accountId, int $organizationId): Account
    {
        $account = Account::withoutGlobalScopes()->find($accountId);

        if (!$account) {
            throw new InvalidArgumentException("Account with ID {$accountId} not found.");
        }

        if ((int)$account->organization_id !== $organizationId) {
            throw new CrossTenantViolationException("Account {$account->code} does not belong to organization {$organizationId}.");
        }

        if (!$account->is_postable) {
            throw new NonPostableAccountException($account->code, $account->name);
        }

        return $account;
    }

    public function resolveAccount(int $organizationId, string $mappingType, string $key): ?Account
    {
        $mapping = AccountMapping::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('mapping_type', $mappingType)
            ->where('mapping_key', $key)
            ->where('is_active', true)
            ->first();

        return $mapping ? $mapping->account : null;
    }

    private function getDescendantIds(int $accountId): array
    {
        $ids = [];
        $children = Account::withoutGlobalScopes()->where('parent_id', $accountId)->pluck('id')->toArray();
        foreach ($children as $childId) {
            $ids[] = $childId;
            $ids = array_merge($ids, $this->getDescendantIds($childId));
        }

        return $ids;
    }
}
