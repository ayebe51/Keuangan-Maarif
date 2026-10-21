<?php

namespace App\Domain\Classification\DTOs;

class AiSuggestionResult
{
    public function __construct(
        public readonly float $confidence,
        public readonly string $suggestedType,
        public readonly ?int $suggestedAccountId = null,
        public readonly ?int $suggestedCategoryId = null,
        public readonly ?int $suggestedCounterpartyId = null,
        public readonly string $evidence = '',
        public readonly string $explanation = '',
        public readonly array $metadata = []
    ) {}
}
