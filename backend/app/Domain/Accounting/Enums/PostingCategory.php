<?php

namespace App\Domain\Accounting\Enums;

use App\Domain\Accounting\Exceptions\UnsupportedPostingCategoryException;
use App\Domain\Classification\Enums\BusinessTransactionType;

enum PostingCategory: string
{
    case RECEIVABLE_PAYMENT = 'RECEIVABLE_PAYMENT';
    case DIRECT_INCOME = 'DIRECT_INCOME';
    case BANK_INTEREST = 'BANK_INTEREST';
    case BANK_FEE_TAX = 'BANK_FEE_TAX';
    case OPERATING_EXPENSE = 'OPERATING_EXPENSE';
    case ALLOCATION_DISBURSEMENT = 'ALLOCATION_DISBURSEMENT';
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case UNAPPLIED_CASH = 'UNAPPLIED_CASH';

    /**
     * @return string[]
     */
    public function allowedBankDirections(): array
    {
        return match ($this) {
            self::RECEIVABLE_PAYMENT,
            self::DIRECT_INCOME,
            self::BANK_INTEREST,
            self::UNAPPLIED_CASH => ['IN'],

            self::BANK_FEE_TAX,
            self::OPERATING_EXPENSE,
            self::ALLOCATION_DISBURSEMENT => ['OUT'],

            self::BANK_TRANSFER => ['IN', 'OUT', 'TRANSFER'],
        };
    }

    public function isDirectionAllowed(string $direction): bool
    {
        return in_array(strtoupper(trim($direction)), $this->allowedBankDirections(), true);
    }

    /**
     * Map from existing transaction type (string or BusinessTransactionType) to PostingCategory.
     * Fails closed with UnsupportedPostingCategoryException on unrecognized types.
     */
    public static function fromTransactionType(string|BusinessTransactionType $type): self
    {
        $raw = $type instanceof BusinessTransactionType ? $type->value : strtoupper(trim($type));

        return match ($raw) {
            'RECEIVABLE_PAYMENT' => self::RECEIVABLE_PAYMENT,
            'DIRECT_INCOME' => self::DIRECT_INCOME,
            'BANK_INTEREST' => self::BANK_INTEREST,
            'BANK_FEE_TAX' => self::BANK_FEE_TAX,
            'OPERATING_EXPENSE' => self::OPERATING_EXPENSE,
            'ALLOCATION_DISBURSEMENT' => self::ALLOCATION_DISBURSEMENT,
            'BANK_TRANSFER' => self::BANK_TRANSFER,
            'UNAPPLIED_CASH' => self::UNAPPLIED_CASH,
            default => throw new UnsupportedPostingCategoryException($raw),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::RECEIVABLE_PAYMENT => 'Pelunasan Piutang Madrasah',
            self::DIRECT_INCOME => 'Pendapatan Langsung',
            self::BANK_INTEREST => 'Pendapatan Bunga Bank / Jasa Giro',
            self::BANK_FEE_TAX => 'Beban Administrasi / Pajak Bunga Bank',
            self::OPERATING_EXPENSE => 'Beban Operasional Kantor',
            self::ALLOCATION_DISBURSEMENT => 'Penyaluran Alokasi Dana Khusus',
            self::BANK_TRANSFER => 'Pemindahbukuan Antar Rekening Bank',
            self::UNAPPLIED_CASH => 'Titipan Kas / Unapplied Cash',
        };
    }
}
