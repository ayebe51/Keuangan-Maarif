<?php

namespace App\Domain\Classification\Enums;

enum BusinessTransactionType: string
{
    case RECEIVABLE_PAYMENT = 'RECEIVABLE_PAYMENT';
    case DIRECT_INCOME = 'DIRECT_INCOME';
    case BANK_INTEREST = 'BANK_INTEREST';
    case BANK_FEE_TAX = 'BANK_FEE_TAX';
    case OPERATING_EXPENSE = 'OPERATING_EXPENSE';
    case ALLOCATION_DISBURSEMENT = 'ALLOCATION_DISBURSEMENT';
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case CASH_TRANSACTION = 'CASH_TRANSACTION';

    /**
     * @return string[]
     */
    public function allowedDirections(): array
    {
        return match ($this) {
            self::RECEIVABLE_PAYMENT,
            self::DIRECT_INCOME,
            self::BANK_INTEREST => ['IN'],

            self::BANK_FEE_TAX,
            self::OPERATING_EXPENSE,
            self::ALLOCATION_DISBURSEMENT => ['OUT'],

            self::BANK_TRANSFER,
            self::CASH_TRANSACTION => ['IN', 'OUT', 'TRANSFER'],
        };
    }

    public function isDirectionAllowed(string $direction): bool
    {
        return in_array(strtoupper($direction), $this->allowedDirections(), true);
    }
}
