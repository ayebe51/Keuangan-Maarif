<?php

namespace App\Domain\Classification\Enums;

enum ClassificationMethod: string
{
    case EXPLICIT_MAPPING = 'EXPLICIT_MAPPING';
    case INVOICE_REFERENCE = 'INVOICE_REFERENCE';
    case LEGACY_CODE = 'LEGACY_CODE';
    case DETERMINISTIC_RULE = 'DETERMINISTIC_RULE';
    case HISTORICAL_RULE = 'HISTORICAL_RULE';
    case AI_SUGGESTION = 'AI_SUGGESTION';
    case MANUAL = 'MANUAL';

    public function isAutoPostEligible(): bool
    {
        return match ($this) {
            self::EXPLICIT_MAPPING,
            self::INVOICE_REFERENCE,
            self::LEGACY_CODE,
            self::DETERMINISTIC_RULE => true,
            self::HISTORICAL_RULE,
            self::AI_SUGGESTION,
            self::MANUAL => false,
        };
    }
}
