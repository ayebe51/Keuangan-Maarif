<?php

namespace App\Domain\Classification\Enums;

enum ClassificationRuleType: string
{
    case DETERMINISTIC = 'DETERMINISTIC';
    case KEYWORD = 'KEYWORD';
    case REGEX = 'REGEX';
    case EXACT_REFERENCE = 'EXACT_REFERENCE';
    case COUNTERPARTY = 'COUNTERPARTY';
}
