<?php

namespace App\Domain\Accounting\Exceptions;

class AutomatedPostingAlreadyExecutedException extends AlreadyPostedException
{
    public function __construct(string $identifier, string $message = '')
    {
        $msg = $message ?: "Operation rejected: '{$identifier}' has already been posted to the ledger.";
        parent::__construct($identifier, $msg);
    }
}
