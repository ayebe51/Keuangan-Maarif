<?php

namespace App\Domain\Classification\Services;

use App\Domain\Accounting\Services\AccountService;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Classification\Enums\BusinessTransactionType;
use App\Domain\Classification\Exceptions\InvalidClassificationDirectionException;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ClassificationRuleService
{
    public function __construct(
        protected AccountService $accountService
    ) {}

    public function create(array $data, ?User $user = null): ClassificationRule
    {
        return DB::transaction(function () use ($data, $user) {
            $orgId = (int) $data['organization_id'];

            $txType = $data['transaction_type'] ?? '';
            $bizType = BusinessTransactionType::tryFrom($txType);
            if (!$bizType) {
                throw new InvalidArgumentException("Invalid transaction type '{$txType}'.");
            }

            $direction = isset($data['direction']) ? strtoupper($data['direction']) : null;
            if ($direction && !$bizType->isDirectionAllowed($direction)) {
                throw new InvalidClassificationDirectionException(
                    "Direction '{$direction}' is incompatible with transaction type '{$txType}'."
                );
            }

            if (!empty($data['target_account_id'])) {
                $this->accountService->ensurePostable((int) $data['target_account_id'], $orgId);
            }

            $rule = ClassificationRule::create([
                'organization_id' => $orgId,
                'name' => $data['name'],
                'priority' => $data['priority'] ?? 100,
                'is_active' => $data['is_active'] ?? true,
                'rule_type' => $data['rule_type'] ?? 'KEYWORD',
                'direction' => $direction,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'pattern' => $data['pattern'] ?? '',
                'pattern_type' => $data['pattern_type'] ?? 'contains',
                'min_amount' => $data['min_amount'] ?? null,
                'max_amount' => $data['max_amount'] ?? null,
                'transaction_type' => $txType,
                'transaction_category_id' => $data['transaction_category_id'] ?? null,
                'target_account_id' => $data['target_account_id'] ?? null,
                'counterparty_id' => $data['counterparty_id'] ?? null,
                'fund_id' => $data['fund_id'] ?? null,
                'auto_post' => $data['auto_post'] ?? true,
                'confidence' => $data['confidence'] ?? 1.00,
                'description' => $data['description'] ?? null,
            ]);

            if ($user) {
                AuditService::log(
                    action: 'CLASSIFICATION_RULE_CREATED',
                    modelType: ClassificationRule::class,
                    modelId: $rule->id,
                    newValues: $rule->toArray(),
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $rule;
        });
    }

    public function update(ClassificationRule $rule, array $data, ?User $user = null): ClassificationRule
    {
        return DB::transaction(function () use ($rule, $data, $user) {
            $orgId = (int) $rule->organization_id;

            if (array_key_exists('organization_id', $data) && (int) $data['organization_id'] !== $orgId) {
                throw new CrossTenantViolationException("Cannot change organization of classification rule.");
            }

            $txType = $data['transaction_type'] ?? $rule->transaction_type;
            $bizType = BusinessTransactionType::tryFrom($txType);
            if (!$bizType) {
                throw new InvalidArgumentException("Invalid transaction type '{$txType}'.");
            }

            $direction = array_key_exists('direction', $data)
                ? ($data['direction'] !== null ? strtoupper($data['direction']) : null)
                : $rule->direction;

            if ($direction && !$bizType->isDirectionAllowed($direction)) {
                throw new InvalidClassificationDirectionException(
                    "Direction '{$direction}' is incompatible with transaction type '{$txType}'."
                );
            }

            if (!empty($data['target_account_id'])) {
                $this->accountService->ensurePostable((int) $data['target_account_id'], $orgId);
            }

            $before = $rule->toArray();
            $rule->update($data);

            if ($user) {
                AuditService::log(
                    action: 'CLASSIFICATION_RULE_UPDATED',
                    modelType: ClassificationRule::class,
                    modelId: $rule->id,
                    oldValues: $before,
                    newValues: $rule->fresh()->toArray(),
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $rule;
        });
    }

    public function delete(ClassificationRule $rule, ?User $user = null): void
    {
        DB::transaction(function () use ($rule, $user) {
            $orgId = (int) $rule->organization_id;
            $before = $rule->toArray();

            $rule->delete();

            if ($user) {
                AuditService::log(
                    action: 'CLASSIFICATION_RULE_DELETED',
                    modelType: ClassificationRule::class,
                    modelId: $rule->id,
                    oldValues: $before,
                    userId: $user->id,
                    organizationId: $orgId
                );
            }
        });
    }

    public function reorder(int $organizationId, array $ruleIdsInOrder, ?User $user = null): void
    {
        DB::transaction(function () use ($organizationId, $ruleIdsInOrder, $user) {
            $prio = 10;
            foreach ($ruleIdsInOrder as $ruleId) {
                ClassificationRule::withoutGlobalScopes()
                    ->where('organization_id', $organizationId)
                    ->where('id', $ruleId)
                    ->update(['priority' => $prio]);
                $prio += 10;
            }

            if ($user) {
                AuditService::log(
                    action: 'CLASSIFICATION_RULES_REORDERED',
                    modelType: ClassificationRule::class,
                    modelId: 0,
                    newValues: ['order' => $ruleIdsInOrder],
                    userId: $user->id,
                    organizationId: $organizationId
                );
            }
        });
    }
}
