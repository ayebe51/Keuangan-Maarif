<?php

namespace App\Domain\Bank\Enums;

enum BankTransactionStatus: string
{
    case UNMATCHED = 'unmatched';
    case MATCHED = 'matched';
    case RECONCILED = 'reconciled';
    case EXCLUDED = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::UNMATCHED => 'Belum Dicocokkan',
            self::MATCHED => 'Cocok / Terklasifikasi',
            self::RECONCILED => 'Terekonsiliasi',
            self::EXCLUDED => 'Dikecualikan',
        };
    }
}
