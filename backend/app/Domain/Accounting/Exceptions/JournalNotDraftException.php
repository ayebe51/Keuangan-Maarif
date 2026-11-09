<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class JournalNotDraftException extends RuntimeException
{
    public function __construct(string $entryNumber, string $currentStatus, string $message = '')
    {
        $msg = $message ?: "Operation rejected: Journal '{$entryNumber}' is in '{$currentStatus}' status. Only DRAFT journals can be posted.";
        parent::__construct($msg, 422);
    }
}
