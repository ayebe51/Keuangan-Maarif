<?php

namespace App\Domain\Classification\Exceptions;

use DomainException;

class ImmutableClassifiedTransactionException extends DomainException
{
    public function __construct(string $message = "Cannot modify or reclassify a posted business transaction. Use formal reversal procedures.")
    {
        parent::__construct($message, 409);
    }
}
