<?php

namespace App\Domain\Bank\Parsers;

use App\Domain\Bank\Exceptions\UnsupportedBankFormatException;
use App\Domain\Bank\Parsers\Contracts\BankStatementParserInterface;

class BankStatementParserFactory
{
    /**
     * Resolve appropriate parser for a given file name/path.
     */
    public static function createForFile(string $filePath): BankStatementParserInterface
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv', 'txt' => new CsvBankStatementParser(),
            'xlsx' => new XlsxBankStatementParser(),
            default => throw new UnsupportedBankFormatException($extension),
        };
    }
}
