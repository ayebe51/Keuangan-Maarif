<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class InvalidAutomatedPostingSourceException extends RuntimeException
{
    public function __construct(string $message = 'Journal draft is not linked to the specified BusinessTransaction source.', int $code = 422)
    {
        parent::__construct($message, $code);
    }
}
