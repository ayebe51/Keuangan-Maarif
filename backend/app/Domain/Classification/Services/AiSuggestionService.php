<?php

namespace App\Domain\Classification\Services;

use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Contracts\AiSuggestionInterface;
use App\Domain\Classification\DTOs\AiSuggestionResult;
use App\Domain\Classification\DTOs\ClassificationMatchResult;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Providers\HeuristicAiSuggestionProvider;

class AiSuggestionService implements AiSuggestionInterface
{
    public function __construct(
        protected ?AiSuggestionInterface $provider = null
    ) {
        $this->provider = $provider ?? new HeuristicAiSuggestionProvider();
    }

    public function suggest(BankTransaction $transaction, array $context = []): ?AiSuggestionResult
    {
        return $this->provider->suggest($transaction, $context);
    }

    /**
     * Wrap suggestion as Priority 6 in classification precedence hierarchy.
     * Note: AI Suggestions NEVER auto-post.
     */
    public function match(BankTransaction $transaction, array $context = []): ?ClassificationMatchResult
    {
        $suggestion = $this->suggest($transaction, $context);
        if (!$suggestion) {
            return null;
        }

        return new ClassificationMatchResult(
            isMatched: true,
            priority: 6,
            method: ClassificationMethod::AI_SUGGESTION,
            confidence: $suggestion->confidence,
            transactionType: $suggestion->suggestedType,
            targetAccountId: $suggestion->suggestedAccountId,
            transactionCategoryId: $suggestion->suggestedCategoryId,
            counterpartyId: $suggestion->suggestedCounterpartyId,
            autoPostEligible: false, // AI MUST NEVER AUTO-POST! Invariant REV3-10
            evidence: $suggestion->evidence,
            explanation: $suggestion->explanation,
            metadata: $suggestion->metadata
        );
    }
}
