<?php

namespace App\Domain\Accounting\Enums;

enum TransferPairingStatus: string
{
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
    case PENDING_PAIRING = 'PENDING_PAIRING';
    case PAIRED = 'PAIRED';
    case PAIRING_AMBIGUOUS = 'PAIRING_AMBIGUOUS';
    case PAIRING_FAILED = 'PAIRING_FAILED';

    public function label(): string
    {
        return match ($this) {
            self::NOT_APPLICABLE => 'Bukan Transfer Internal',
            self::PENDING_PAIRING => 'Menunggu Pasangan Transfer',
            self::PAIRED => 'Terpasang (2:1 Paired)',
            self::PAIRING_AMBIGUOUS => 'Pasangan Ambigu (Butuh Review)',
            self::PAIRING_FAILED => 'Pemasangan Gagal',
        };
    }

    public function isPaired(): bool
    {
        return $this === self::PAIRED;
    }

    public function isPending(): bool
    {
        return $this === self::PENDING_PAIRING;
    }

    public function isAmbiguous(): bool
    {
        return $this === self::PAIRING_AMBIGUOUS;
    }

    public function isFailed(): bool
    {
        return $this === self::PAIRING_FAILED;
    }

    public function isApplicable(): bool
    {
        return $this !== self::NOT_APPLICABLE;
    }
}
