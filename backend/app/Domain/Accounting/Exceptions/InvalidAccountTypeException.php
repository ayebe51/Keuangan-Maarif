<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class InvalidAccountTypeException extends RuntimeException
{
    public function __construct(string $accountCode, string $actualType, string $expectedType, string $message = '')
    {
        $msg = $message ?: "Account '{$accountCode}' has invalid type '{$actualType}'. Expected '{$expectedType}'.";
        parent::__construct($msg, 422);
    }
}
