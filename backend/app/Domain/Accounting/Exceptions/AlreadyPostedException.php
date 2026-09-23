<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class AlreadyPostedException extends RuntimeException
{
    public function __construct(string $identifier, string $message = '')
    {
        $msg = $message ?: "Operation rejected: '{$identifier}' has already been processed and posted to the ledger.";
        parent::__construct($msg, 422);
    }
}
