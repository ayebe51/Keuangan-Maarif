<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class RuleNotApprovedException extends RuntimeException
{
    public function __construct(int $ruleId, string $message = '')
    {
        $msg = $message ?: "Classification rule #{$ruleId} has not been approved and cannot authorize automated posting.";
        parent::__construct($msg, 422);
    }
}
