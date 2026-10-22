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
