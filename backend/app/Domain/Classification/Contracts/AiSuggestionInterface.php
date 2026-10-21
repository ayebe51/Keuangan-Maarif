<?php

namespace App\Domain\Classification\Contracts;

use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\DTOs\AiSuggestionResult;

interface AiSuggestionInterface
{
    /**
     * Generate structured suggestion for an unclassified transaction.
     * Note: Suggestions NEVER auto-post.
     */
    public function suggest(BankTransaction $transaction, array $context = []): ?AiSuggestionResult;
}
