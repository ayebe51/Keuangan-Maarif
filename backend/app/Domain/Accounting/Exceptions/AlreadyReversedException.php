<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class AlreadyReversedException extends RuntimeException
{
    public function __construct(string $entryNumber, string $message = '')
    {
        $msg = $message ?: "Cannot reverse journal '{$entryNumber}': Journal has already been reversed.";
        parent::__construct($msg, 422);
    }
}
