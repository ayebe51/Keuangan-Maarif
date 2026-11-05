<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class AmbiguousAccountMappingException extends RuntimeException
{
    public function __construct(string $target, string $message = '')
    {
        $msg = $message ?: "Ambiguous or conflicting account mappings detected for '{$target}'. Manual review required.";
        parent::__construct($msg, 422);
    }
}
