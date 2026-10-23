<?php

namespace App\Domain\Classification\Matchers;

use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\DTOs\ClassificationMatchResult;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Models\BusinessTransaction;
use Illuminate\Support\Facades\DB;

class HistoricalHeuristicMatcher
{
    /**
     * Match bank transaction against historical recurring patterns (Priority 5).
     * NOTE: This yields SUGGESTION ONLY. Never auto-post.
     */
    public function match(BankTransaction $transaction): ?ClassificationMatchResult
    {
        if (!$transaction->counterparty_id) {
            return null;
        }

        $orgId = (int) $transaction->organization_id;
        $cpId = (int) $transaction->counterparty_id;
        $txDir = is_object($transaction->direction) ? $transaction->direction->value : (string) $transaction->direction;

        // Find most frequent transaction type and account for this counterparty and direction
        $historical = DB::table('business_transactions')
            ->select('transaction_type', 'target_account_id', 'transaction_category_id', DB::raw('COUNT(*) as occurrences'))
            ->where('organization_id', $orgId)
            ->where('counterparty_id', $cpId)
            ->where('direction', $txDir)
            ->whereIn('status', ['approved', 'posted'])
            ->whereNotNull('target_account_id')
            ->groupBy('transaction_type', 'target_account_id', 'transaction_category_id')
            ->orderByDesc('occurrences')
            ->first();

        if (!$historical || (int) $historical->occurrences < 1) {
            return null;
        }

        $occurrences = (int) $historical->occurrences;
        $confidence = min(0.85, 0.65 + ($occurrences * 0.05));

        return new ClassificationMatchResult(
            isMatched: true,
            priority: 5,
            method: ClassificationMethod::HISTORICAL_RULE,
            confidence: $confidence,
            transactionType: $historical->transaction_type,
            targetAccountId: $historical->target_account_id ? (int) $historical->target_account_id : null,
            transactionCategoryId: $historical->transaction_category_id ? (int) $historical->transaction_category_id : null,
            counterpartyId: $cpId,
            autoPostEligible: false, // SUGGESTION ONLY! Must never auto-post
            evidence: "Detected recurring historical pattern with counterparty #{$cpId} ({$occurrences} previous posted transactions)",
            explanation: "Suggested based on past transactions for this counterparty.",
            metadata: [
                'occurrences' => $occurrences,
                'counterparty_id' => $cpId,
            ]
        );
    }
}
