<?php

namespace App\Domain\Classification\Matchers;

use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\DTOs\ClassificationMatchResult;
use App\Domain\Classification\Enums\BusinessTransactionType;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Models\ClassificationRule;

class DeterministicRuleMatcher
{
    /**
     * Match bank transaction against deterministic category / text rules (Priority 4).
     *
     * @param ClassificationRule[] $rules Optional preloaded rules
     */
    public function match(BankTransaction $transaction, array $rules = []): ?ClassificationMatchResult
    {
        $orgId = (int) $transaction->organization_id;

        $candidates = !empty($rules)
            ? array_filter($rules, fn ($r) => $r->is_active && $r->priority > 10 && $r->rule_type !== 'EXACT_REFERENCE')
            : ClassificationRule::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('is_active', true)
                ->where('priority', '>', 10)
                ->where('rule_type', '!=', 'EXACT_REFERENCE')
                ->orderBy('priority', 'asc')
                ->orderBy('id', 'asc')
                ->get();

        $txDir = is_object($transaction->direction) ? $transaction->direction->value : (string) $transaction->direction;

        foreach ($candidates as $rule) {
            if ($rule->matches($transaction)) {
                // Ensure direction is compatible with transaction type
                $bizType = BusinessTransactionType::tryFrom($rule->transaction_type);
                if ($bizType && !$bizType->isDirectionAllowed($txDir)) {
                    continue; // Skip conflicting rule
                }

                return new ClassificationMatchResult(
                    isMatched: true,
                    priority: 4,
                    method: ClassificationMethod::DETERMINISTIC_RULE,
                    confidence: (float) $rule->confidence,
                    transactionType: $rule->transaction_type,
                    targetAccountId: $rule->target_account_id,
                    transactionCategoryId: $rule->transaction_category_id,
                    counterpartyId: $rule->counterparty_id ?? $transaction->counterparty_id,
                    fundId: $rule->fund_id,
                    autoPostEligible: (bool) $rule->auto_post,
                    evidence: "Deterministic Rule #{$rule->id} ('{$rule->name}') matched pattern '{$rule->pattern}'",
                    explanation: "Matched category rule: {$rule->name}",
                    metadata: [
                        'rule_id' => $rule->id,
                        'rule_name' => $rule->name,
                        'pattern' => $rule->pattern,
                    ]
                );
            }
        }

        return null;
    }
}
