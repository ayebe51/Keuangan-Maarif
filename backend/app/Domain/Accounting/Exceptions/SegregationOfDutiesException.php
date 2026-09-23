<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class SegregationOfDutiesException extends RuntimeException
{
    public function __construct(int $userId, string $entryNumber, string $message = '')
    {
        $msg = $message ?: "Segregation of Duties Violation: User {$userId} created journal {$entryNumber} and cannot post/approve it. Maker cannot be checker.";
        parent::__construct($msg, 403);
    }
}
