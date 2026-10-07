<?php

namespace App\Domain\Bank\Enums;

enum BankTransactionDirection: string
{
    // BANK SEMANTICS INVARIANT:
    // IN  => Bank CREDIT => Cash received (money coming in)
    // OUT => Bank DEBIT  => Cash paid out (money going out)
    // Bank CREDIT ≠ Accounting CREDIT
    case IN = 'IN';
    case OUT = 'OUT';

    public function isCashIn(): bool
    {
        return $this === self::IN;
    }

    public function isCashOut(): bool
    {
        return $this === self::OUT;
    }

    public function label(): string
    {
        return match ($this) {
            self::IN => 'Uang Masuk (Kredit Bank)',
            self::OUT => 'Uang Keluar (Debet Bank)',
        };
    }
}
