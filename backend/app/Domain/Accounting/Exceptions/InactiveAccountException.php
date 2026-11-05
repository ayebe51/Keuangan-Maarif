<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class InactiveAccountException extends RuntimeException
{
    public function __construct(string $accountCode, string $accountName, string $message = '')
    {
        $msg = $message ?: "Account '{$accountCode} - {$accountName}' is marked as inactive and cannot be used in accounting entries.";
        parent::__construct($msg, 422);
    }
}
