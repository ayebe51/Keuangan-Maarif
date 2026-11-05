<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class InvalidFundAccountMappingException extends RuntimeException
{
    public function __construct(string $fundCode, string $message = '')
    {
        $msg = $message ?: "Invalid or missing liability account mapping for fund '{$fundCode}'. Fund disbursement requires a postable restricted fund/liability account.";
        parent::__construct($msg, 422);
    }
}
