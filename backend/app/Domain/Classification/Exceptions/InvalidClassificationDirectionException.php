<?php

namespace App\Domain\Classification\Exceptions;

use DomainException;

class InvalidClassificationDirectionException extends DomainException
{
    public function __construct(string $message = "Transaction direction is incompatible with transaction type or account normal balance.")
    {
        parent::__construct($message, 422);
    }
}
