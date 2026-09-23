<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class ImmutableJournalException extends RuntimeException
{
    public function __construct(string $entryNumber, string $action = 'modify', string $message = '')
    {
        $msg = $message ?: "Cannot {$action} journal entry '{$entryNumber}': Posted or reversed entries and their lines are strictly immutable.";
        parent::__construct($msg, 403);
    }
}
