<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class RuleSnapshotMismatchException extends RuntimeException
{
    public function __construct(string $message = 'Rule snapshot or version mismatch detected before automated posting.', int $code = 422)
    {
        parent::__construct($message, $code);
    }
}
