<?php

namespace App\Domain\Classification\Matchers;

use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\DTOs\ClassificationMatchResult;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Models\ClassificationRule;

class ExplicitMappingMatcher
{
    /**
     * Check for explicit user-defined mapping rules (priority <= 10 or EXACT_REFERENCE).
     */
    public function match(BankTransaction $transaction, array $rules = []): ?ClassificationMatchResult
    {
        $orgId = (int) $transaction->organization_id;

        // If preloaded rules not provided, query DB
        $candidates = !empty($rules)
            ? array_filter($rules, fn ($r) => $r->is_active && ($r->priority <= 10 || $r->rule_type === 'EXACT_REFERENCE'))
            : ClassificationRule::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->where('priority', '<=', 10)
                      ->orWhere('rule_type', 'EXACT_REFERENCE');
                })
                ->orderBy('priority', 'asc')
                ->get();

        foreach ($candidates as $rule) {
            if ($rule->matches($transaction)) {
                return new ClassificationMatchResult(
                    isMatched: true,
                    priority: 1,
                    method: ClassificationMethod::EXPLICIT_MAPPING,
                    confidence: 1.00,
                    transactionType: $rule->transaction_type,
                    targetAccountId: $rule->target_account_id,
                    transactionCategoryId: $rule->transaction_category_id,
                    counterpartyId: $rule->counterparty_id ?? $transaction->counterparty_id,
                    fundId: $rule->fund_id,
                    autoPostEligible: true,
                    evidence: "Explicit User Mapping #{$rule->id} ('{$rule->name}') matched",
                    explanation: "Matched pre-approved explicit mapping rule: {$rule->name}"
                );
            }
        }

        return null;
    }
}
