<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class UnresolvedAccountMappingException extends RuntimeException
{
    public function __construct(string $target, string $message = '')
    {
        $msg = $message ?: "Unable to resolve active, postable account mapping for target role '{$target}'.";
        parent::__construct($msg, 422);
    }
}
