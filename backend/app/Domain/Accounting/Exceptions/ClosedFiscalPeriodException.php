<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class ClosedFiscalPeriodException extends RuntimeException
{
    public function __construct(string $date, string $status = 'closed', string $message = '')
    {
        $msg = $message ?: "Cannot post transaction on date '{$date}': Fiscal period is {$status}.";
        parent::__construct($msg, 422);
    }
}
