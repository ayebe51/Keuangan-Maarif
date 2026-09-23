<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class UnbalancedJournalException extends RuntimeException
{
    public function __construct(string $debit, string $credit, string $message = '')
    {
        $msg = $message ?: "Journal entry is unbalanced: Total Debit ({$debit}) does not equal Total Credit ({$credit}).";
        parent::__construct($msg, 422);
    }
}
