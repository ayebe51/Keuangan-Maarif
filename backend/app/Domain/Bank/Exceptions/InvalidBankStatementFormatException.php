<?php

namespace App\Domain\Bank\Exceptions;

use Exception;

class InvalidBankStatementFormatException extends Exception
{
    public function __construct(
        public readonly string $filename,
        public readonly string $details = '',
        string $message = ""
    ) {
        parent::__construct(
            $message ?: "Invalid bank statement format in file '{$filename}': {$details}"
        );
    }
}
