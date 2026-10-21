<?php

namespace App\Domain\Classification\Enums;

enum ClassificationStatus: string
{
    case UNCLASSIFIED = 'unclassified';
    case SUGGESTED = 'suggested';
    case CLASSIFIED = 'classified';
    case REVIEWED = 'reviewed';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public function isPendingReview(): bool
    {
        return in_array($this, [self::UNCLASSIFIED, self::SUGGESTED], true);
    }
}
