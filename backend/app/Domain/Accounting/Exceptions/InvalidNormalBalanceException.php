<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class InvalidNormalBalanceException extends RuntimeException
{
    public function __construct(string $accountCode, string $actualBalance, string $expectedBalance, string $message = '')
    {
        $msg = $message ?: "Account '{$accountCode}' has invalid normal balance '{$actualBalance}'. Expected '{$expectedBalance}'.";
        parent::__construct($msg, 422);
    }
}
