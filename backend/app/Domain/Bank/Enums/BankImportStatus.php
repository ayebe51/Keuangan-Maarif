<?php

namespace App\Domain\Bank\Enums;

enum BankImportStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case DONE = 'done';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu Diproses',
            self::PROCESSING => 'Sedang Diproses',
            self::DONE => 'Selesai',
            self::FAILED => 'Gagal',
        };
    }
}
