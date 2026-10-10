<?php

namespace App\Domain\Bank\Exceptions;

use Exception;

class UnsupportedBankFormatException extends Exception
{
    public function __construct(
        public readonly string $format,
        string $message = ""
    ) {
        parent::__construct(
            $message ?: "Unsupported bank statement format: '{$format}'."
        );
    }
}
