<?php

namespace App\Domain\Accounting\Enums;

enum AutoPostDecisionOutcome: string
{
    case ELIGIBLE = 'ELIGIBLE';
    case INELIGIBLE = 'INELIGIBLE';
    case ALREADY_POSTED = 'ALREADY_POSTED';
    case REQUIRES_HUMAN_REVIEW = 'REQUIRES_HUMAN_REVIEW';
    case BLOCKED = 'BLOCKED';
    case RETRYABLE_FAILURE = 'RETRYABLE_FAILURE';
    case PERMANENT_FAILURE = 'PERMANENT_FAILURE';

    public function isSuccess(): bool
    {
        return $this === self::ELIGIBLE;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::ALREADY_POSTED, self::PERMANENT_FAILURE, self::BLOCKED], true);
    }
}
