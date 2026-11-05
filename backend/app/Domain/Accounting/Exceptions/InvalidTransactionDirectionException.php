<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class InvalidTransactionDirectionException extends RuntimeException
{
    public function __construct(string $category, string $direction, string $message = '')
    {
        $msg = $message ?: "Invalid transaction direction '{$direction}' for posting category '{$category}'. Expected directions: ".self::expectedMessage($category);
        parent::__construct($msg, 422);
    }

    private static function expectedMessage(string $category): string
    {
        return match (strtoupper($category)) {
            'RECEIVABLE_PAYMENT', 'DIRECT_INCOME', 'BANK_INTEREST', 'UNAPPLIED_CASH' => 'IN',
            'BANK_FEE_TAX', 'OPERATING_EXPENSE', 'ALLOCATION_DISBURSEMENT' => 'OUT',
            'BANK_TRANSFER' => 'IN, OUT, or TRANSFER',
            default => 'Unknown',
        };
    }
}
