<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class AutoPostAmountExceededException extends RuntimeException
{
    public function __construct(string $amount, string $maxAmount, int $ruleId, string $message = '')
    {
        $msg = $message ?: "Transaction amount '{$amount}' exceeds maximum auto-post threshold '{$maxAmount}' authorized by Rule #{$ruleId}.";
        parent::__construct($msg, 422);
    }
}
