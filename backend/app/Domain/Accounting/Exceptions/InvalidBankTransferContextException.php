<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class InvalidBankTransferContextException extends RuntimeException
{
    public function __construct(string $message = 'Invalid or incomplete bank transfer context. Distinct source and destination accounts are required.')
    {
        parent::__construct($message, 422);
    }
}
