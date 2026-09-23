<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class NonPostableAccountException extends RuntimeException
{
    public function __construct(string $accountCode, string $accountName, string $message = '')
    {
        $msg = $message ?: "Account '{$accountCode} - {$accountName}' is a summary/header account and is not postable.";
        parent::__construct($msg, 422);
    }
}
