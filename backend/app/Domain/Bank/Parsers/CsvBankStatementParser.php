<?php

namespace App\Domain\Bank\Parsers;

use App\Domain\Bank\Exceptions\InvalidBankStatementFormatException;
use App\Domain\Bank\Parsers\Contracts\BankStatementParserInterface;
use App\Domain\Bank\Parsers\Contracts\StatementMappingProfileInterface;
use App\Domain\Bank\Parsers\Profiles\BriGiroMappingProfile;
use App\Domain\Bank\Parsers\Profiles\BriTabunganMappingProfile;
use App\Domain\Bank\Parsers\Profiles\BukuKasMappingProfile;
use App\Domain\Bank\Parsers\Profiles\GenericCsvMappingProfile;

class CsvBankStatementParser implements BankStatementParserInterface
{
    /** @var StatementMappingProfileInterface[] */
    protected array $profiles;

    public function __construct()
    {
        $this->profiles = [
            new BukuKasMappingProfile(),
            new BriTabunganMappingProfile(),
            new BriGiroMappingProfile(),
            new GenericCsvMappingProfile(),
        ];
    }

    public function parse(string $filePath, array $options = []): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new InvalidBankStatementFormatException(basename($filePath), "File does not exist or is not readable.");
        }

        $content = file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            throw new InvalidBankStatementFormatException(basename($filePath), "File is empty.");
        }

        // Remove UTF-8 BOM if present
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // Detect delimiter: count occurrences in first 5 lines
        $delimiter = $this->detectDelimiter($content);

        $lines = preg_split("/\r\n|\n|\r/", trim($content));
        if (empty($lines)) {
            return [];
        }

        $headers = [];
        $rows = [];
        $headerRowIdx = null;

        // Find header row (first non-empty row that contains keyword like tgl, tanggal, date, etc.)
        foreach ($lines as $idx => $line) {
            $line = trim($line);
            if ($line === '') continue;

            $parsedLine = str_getcsv($line, $delimiter);
            if ($this->looksLikeHeader($parsedLine)) {
                $headers = array_map(fn($h) => trim((string)$h), $parsedLine);
                $headerRowIdx = $idx;
                break;
            }
        }

        $profile = $this->resolveProfile($headers, $options['mapping_version'] ?? null);

        $sourceFile = basename($filePath);
        $result = [];
        $dataRowIdx = 0;

        foreach ($lines as $lineIdx => $line) {
            $line = trim($line);
            if ($line === '') continue;
            if ($headerRowIdx !== null && $lineIdx <= $headerRowIdx) continue;

            $cells = str_getcsv($line, $delimiter);
            // Check if all cells are empty
            $nonEmpty = array_filter($cells, fn($c) => trim((string)$c) !== '');
            if (empty($nonEmpty)) continue;

            $dataRowIdx++;
            $mapped = $profile->mapRow($cells, $headers);

            // Skip total/summary rows at bottom (e.g. "JUMLAH", "TOTAL", "SALDO AKHIR")
            $firstCell = strtolower(trim((string)($cells[0] ?? '')));
            $descCell = strtolower(trim((string)($mapped['description'] ?? '')));
            if (str_starts_with($firstCell, 'total') || str_starts_with($firstCell, 'jumlah') ||
                str_starts_with($descCell, 'total') || str_starts_with($descCell, 'jumlah')) {
                continue;
            }

            $rawAssoc = [];
            foreach ($cells as $cIdx => $cVal) {
                $k = $headers[$cIdx] ?? "col_{$cIdx}";
                $rawAssoc[$k] = $cVal;
            }

            $result[] = [
                'source_file' => $sourceFile,
                'source_sheet' => null,
                'source_row' => $lineIdx + 1,
                'raw_cells' => $rawAssoc,
                'date_raw' => $mapped['date'],
                'desc_raw' => $mapped['description'],
                'debit_raw' => $mapped['debit'],
                'credit_raw' => $mapped['credit'],
                'balance_raw' => $mapped['balance'],
                'ref_raw' => $mapped['reference'],
            ];
        }

        return $result;
    }

    protected function detectDelimiter(string $content): string
    {
        $sample = substr($content, 0, 4096);
        $delimiters = [',', ';', "\t", '|'];
        $bestDelimiter = ',';
        $maxCount = -1;

        foreach ($delimiters as $delim) {
            $count = substr_count($sample, $delim);
            if ($count > $maxCount) {
                $maxCount = $count;
                $bestDelimiter = $delim;
            }
        }

        return $bestDelimiter;
    }

    protected function looksLikeHeader(array $cells): bool
    {
        $normalized = array_map(fn($c) => strtolower(trim((string)$c)), $cells);
        foreach ($normalized as $c) {
            if (in_array($c, ['tanggal', 'tgl', 'date', 'keterangan', 'uraian', 'debet', 'kredit', 'penerimaan', 'pengeluaran', 'saldo'])) {
                return true;
            }
        }
        return false;
    }

    protected function resolveProfile(array $headers, ?string $explicitVersion = null): StatementMappingProfileInterface
    {
        if ($explicitVersion) {
            foreach ($this->profiles as $p) {
                if ($p->name() === $explicitVersion) {
                    return $p;
                }
            }
        }

        foreach ($this->profiles as $p) {
            if ($p->matchHeaders($headers)) {
                return $p;
            }
        }

        return new GenericCsvMappingProfile();
    }
}
