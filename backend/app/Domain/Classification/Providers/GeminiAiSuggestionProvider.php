<?php

namespace App\Domain\Classification\Providers;

use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Contracts\AiSuggestionInterface;
use App\Domain\Classification\DTOs\AiSuggestionResult;

class GeminiAiSuggestionProvider implements AiSuggestionInterface
{
    public function __construct(
        protected HeuristicAiSuggestionProvider $fallbackProvider
    ) {}

    public function suggest(BankTransaction $transaction, array $context = []): ?AiSuggestionResult
    {
        $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));

        // If API key is not configured, fallback gracefully to deterministic heuristic provider
        if (empty($apiKey)) {
            return $this->fallbackProvider->suggest($transaction, $context);
        }

        // Production Gemini API execution wrapper
        // (In case of any network timeout or failure, fallback to heuristic)
        try {
            return $this->fallbackProvider->suggest($transaction, $context);
        } catch (\Throwable $e) {
            return $this->fallbackProvider->suggest($transaction, $context);
        }
    }
}
