<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class OpeningBalanceConfigurationException extends RuntimeException
{
    public function __construct(string $reason, string $message = '')
    {
        $msg = $message ?: "Opening Balance Configuration Error: {$reason}";
        parent::__construct($msg, 422);
    }
}
