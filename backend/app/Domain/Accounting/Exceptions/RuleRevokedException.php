<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class RuleRevokedException extends RuntimeException
{
    public function __construct(int $ruleId, string $message = '')
    {
        $msg = $message ?: "Classification rule #{$ruleId} is inactive, revoked, or expired before posting.";
        parent::__construct($msg, 422);
    }
}
