<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class InvalidWorkflowStateException extends RuntimeException
{
    public function __construct(string $message = 'Invalid workflow state transition.')
    {
        parent::__construct($message, 422);
    }
}
