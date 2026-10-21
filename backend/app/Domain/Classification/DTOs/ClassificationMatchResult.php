<?php

namespace App\Domain\Classification\DTOs;

use App\Domain\Classification\Enums\ClassificationMethod;

class ClassificationMatchResult
{
    public function __construct(
        public readonly bool $isMatched,
        public readonly int $priority,
        public readonly ClassificationMethod $method,
        public readonly float $confidence,
        public readonly ?string $transactionType = null,
        public readonly ?int $targetAccountId = null,
        public readonly ?int $transactionCategoryId = null,
        public readonly ?int $counterpartyId = null,
        public readonly ?int $fundId = null,
        public readonly bool $autoPostEligible = false,
        public readonly ?string $evidence = null,
        public readonly ?string $explanation = null,
        public readonly array $metadata = []
    ) {}

    public static function unclassified(string $reason = 'No matching rule found in precedence hierarchy'): self
    {
        return new self(
            isMatched: false,
            priority: 7,
            method: ClassificationMethod::MANUAL,
            confidence: 0.00,
            autoPostEligible: false,
            evidence: 'PRIORITY_7_FALLBACK',
            explanation: $reason
        );
    }
}
