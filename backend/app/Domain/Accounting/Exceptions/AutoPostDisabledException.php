<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class AutoPostDisabledException extends RuntimeException
{
    public function __construct(int $ruleId, string $message = '')
    {
        $msg = $message ?: "Classification rule #{$ruleId} does not permit automated posting (auto_post is disabled or max_auto_post_amount is 0.00).";
        parent::__construct($msg, 422);
    }
}
