<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class InvalidAutomationAuthorizationException extends RuntimeException
{
    public function __construct(string $message = 'Invalid automated posting authorization context.', int $code = 422)
    {
        parent::__construct($message, $code);
    }
}
