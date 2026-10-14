<?php

namespace App\Domain\Bank\Parsers;

use App\Domain\Bank\Exceptions\InvalidBankStatementFormatException;
use App\Domain\Bank\Parsers\Contracts\BankStatementParserInterface;
use App\Domain\Bank\Parsers\Contracts\StatementMappingProfileInterface;
use App\Domain\Bank\Parsers\Profiles\BriGiroMappingProfile;
use App\Domain\Bank\Parsers\Profiles\BriTabunganMappingProfile;
use App\Domain\Bank\Parsers\Profiles\BukuKasMappingProfile;
use App\Domain\Bank\Parsers\Profiles\GenericCsvMappingProfile;
use SimpleXMLElement;
use ZipArchive;

class XlsxBankStatementParser implements BankStatementParserInterface
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

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new InvalidBankStatementFormatException(basename($filePath), "Could not open file as valid OpenXML XLSX archive.");
        }

        try {
            $sharedStrings = $this->loadSharedStrings($zip);
            $sheetInfo = $this->resolveSheetFile($zip, $options);
            $sheetFile = $sheetInfo['file'];
            $sheetName = $sheetInfo['name'];

            $sheetXmlContent = $zip->getFromName($sheetFile);
            if ($sheetXmlContent === false) {
                throw new InvalidBankStatementFormatException(basename($filePath), "Sheet XML '{$sheetFile}' not found in workbook.");
            }

            $sheetXml = simplexml_load_string($sheetXmlContent);
            if ($sheetXml === false || !isset($sheetXml->sheetData)) {
                throw new InvalidBankStatementFormatException(basename($filePath), "Invalid worksheet XML structure.");
            }

            $rawRows = $this->extractRowsFromSheet($sheetXml, $sharedStrings);
            if (empty($rawRows)) {
                return [];
            }

            // Detect headers
            $headers = [];
            $headerRowIdx = null;
            foreach ($rawRows as $rIdx => $cells) {
                if ($this->looksLikeHeader($cells)) {
                    $headers = array_map(fn($c) => trim((string)$c), $cells);
                    $headerRowIdx = $rIdx;
                    break;
                }
            }

            $profile = $this->resolveProfile($headers, $sheetName, $options['mapping_version'] ?? null);
            $sourceFile = basename($filePath);
            $result = [];

            foreach ($rawRows as $rIdx => $cells) {
                if ($headerRowIdx !== null && $rIdx <= $headerRowIdx) continue;

                $nonEmpty = array_filter($cells, fn($c) => trim((string)$c) !== '');
                if (empty($nonEmpty)) continue;

                $mapped = $profile->mapRow($cells, $headers);

                // Skip summary/total rows
                $firstCell = strtolower(trim((string)($cells[0] ?? '')));
                $descCell = strtolower(trim((string)($mapped['description'] ?? '')));
                if (str_starts_with($firstCell, 'total') || str_starts_with($firstCell, 'jumlah') ||
                    str_starts_with($descCell, 'total') || str_starts_with($descCell, 'jumlah') ||
                    str_starts_with($firstCell, 'saldo akhir') || str_starts_with($descCell, 'saldo akhir')) {
                    continue;
                }

                // Format Excel serial date if numeric date detected
                $normalizedDate = $this->normalizeDateValue($mapped['date']);

                $rawAssoc = [];
                foreach ($cells as $cIdx => $cVal) {
                    $k = $headers[$cIdx] ?? "col_{$cIdx}";
                    $rawAssoc[$k] = $cVal;
                }

                $result[] = [
                    'source_file' => $sourceFile,
                    'source_sheet' => $sheetName,
                    'source_row' => $rIdx,
                    'raw_cells' => $rawAssoc,
                    'date_raw' => $normalizedDate,
                    'desc_raw' => $mapped['description'],
                    'debit_raw' => $mapped['debit'],
                    'credit_raw' => $mapped['credit'],
                    'balance_raw' => $mapped['balance'],
                    'ref_raw' => $mapped['reference'],
                ];
            }

            return $result;
        } finally {
            $zip->close();
        }
    }

    protected function loadSharedStrings(ZipArchive $zip): array
    {
        $strings = [];
        $xmlContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlContent === false) {
            return $strings;
        }

        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) {
            return $strings;
        }

        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string)$si->t;
            } elseif (isset($si->r)) {
                $str = '';
                foreach ($si->r as $r) {
                    $str .= (string)$r->t;
                }
                $strings[] = $str;
            } else {
                $strings[] = '';
            }
        }

        return $strings;
    }

    /**
     * Resolve target sheet filename in ZIP archive.
     */
    protected function resolveSheetFile(ZipArchive $zip, array $options): array
    {
        $workbookXmlContent = $zip->getFromName('xl/workbook.xml');
        $relsXmlContent = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXmlContent === false) {
            return ['file' => 'xl/worksheets/sheet1.xml', 'name' => 'Sheet1'];
        }

        $workbookXml = simplexml_load_string($workbookXmlContent);
        $relsXml = $relsXmlContent !== false ? simplexml_load_string($relsXmlContent) : null;

        $relMap = [];
        if ($relsXml && isset($relsXml->Relationship)) {
            foreach ($relsXml->Relationship as $rel) {
                $id = (string)$rel['Id'];
                $target = (string)$rel['Target'];
                if (!str_starts_with($target, 'xl/')) {
                    $target = 'xl/' . ltrim($target, '/');
                }
                $relMap[$id] = $target;
            }
        }

        $sheets = [];
        if (isset($workbookXml->sheets->sheet)) {
            foreach ($workbookXml->sheets->sheet as $sheet) {
                $name = (string)$sheet['name'];
                $sheetId = (string)$sheet['sheetId'];
                $rId = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $targetFile = $relMap[$rId] ?? "xl/worksheets/sheet{$sheetId}.xml";

                $sheets[] = [
                    'name' => $name,
                    'file' => $targetFile,
                ];
            }
        }

        if (empty($sheets)) {
            return ['file' => 'xl/worksheets/sheet1.xml', 'name' => 'Sheet1'];
        }

        // 1. Check explicit sheet_name option
        if (!empty($options['sheet_name'])) {
            $targetName = strtolower(trim((string)$options['sheet_name']));
            foreach ($sheets as $s) {
                if (strtolower($s['name']) === $targetName || str_contains(strtolower($s['name']), $targetName)) {
                    return $s;
                }
            }
        }

        // 2. Check account_number / account_name option (e.g. 308, 304, 538, Kas)
        if (!empty($options['account_number'])) {
            $accountNum = (string)$options['account_number'];
            // Extract key numbers (e.g. 308, 304, 538)
            preg_match('/(\d{3,4})/', $accountNum, $m);
            $keyDigits = $m[1] ?? '';

            if ($keyDigits !== '') {
                foreach ($sheets as $s) {
                    if (str_contains($s['name'], $keyDigits)) {
                        return $s;
                    }
                }
            }
        }

        if (!empty($options['account_type']) && strtolower((string)$options['account_type']) === 'cash') {
            foreach ($sheets as $s) {
                if (str_contains(strtolower($s['name']), 'kas')) {
                    return $s;
                }
            }
        }

        // Fallback to first sheet
        return $sheets[0];
    }

    /**
     * Extract tabular cell rows from sheet XML.
     */
    protected function extractRowsFromSheet(SimpleXMLElement $sheetXml, array $sharedStrings): array
    {
        $rows = [];

        foreach ($sheetXml->sheetData->row as $row) {
            $rowNum = (int)$row['r'];
            $cells = [];

            foreach ($row->c as $cell) {
                $cellRef = (string)$cell['r'];
                $colIndex = $this->columnLetterToIndex($cellRef);
                $type = (string)$cell['t'];
                $value = isset($cell->v) ? (string)$cell->v : '';

                if ($type === 's' && is_numeric($value)) {
                    $strIdx = (int)$value;
                    $actualVal = $sharedStrings[$strIdx] ?? '';
                } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                    $actualVal = (string)$cell->is->t;
                } else {
                    $actualVal = $value;
                }

                $cells[$colIndex] = $actualVal;
            }

            // Fill missing gaps up to highest index
            if (!empty($cells)) {
                $maxCol = max(array_keys($cells));
                $filled = [];
                for ($i = 0; $i <= $maxCol; $i++) {
                    $filled[$i] = $cells[$i] ?? '';
                }
                $rows[$rowNum] = $filled;
            }
        }

        return $rows;
    }

    protected function columnLetterToIndex(string $cellRef): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($cellRef), $matches);
        $colLetters = $matches[1] ?? 'A';
        $index = 0;
        $len = strlen($colLetters);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($colLetters[$i]) - 64);
        }
        return $index - 1; // 0-based
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

    protected function normalizeDateValue(mixed $val): ?string
    {
        if ($val === null || trim((string)$val) === '') {
            return null;
        }

        $str = trim((string)$val);

        // Check if numeric Excel serial date (e.g. 46082 for March 2026)
        if (is_numeric($str) && (float)$str > 30000 && (float)$str < 70000) {
            // Excel epoch is 1899-12-30 (due to 1900 leap year bug)
            $days = (int)$str;
            $seconds = ($days - 25569) * 86400;
            return gmdate('Y-m-d', $seconds);
        }

        return $str;
    }

    protected function resolveProfile(array $headers, string $sheetName, ?string $explicitVersion = null): StatementMappingProfileInterface
    {
        if ($explicitVersion) {
            foreach ($this->profiles as $p) {
                if ($p->name() === $explicitVersion) {
                    return $p;
                }
            }
        }

        $lowerSheet = strtolower($sheetName);
        if (str_contains($lowerSheet, 'kas')) {
            return new BukuKasMappingProfile();
        }
        if (str_contains($lowerSheet, 'tabungan')) {
            return new BriTabunganMappingProfile();
        }
        if (str_contains($lowerSheet, 'giro')) {
            return new BriGiroMappingProfile();
        }

        foreach ($this->profiles as $p) {
            if ($p->matchHeaders($headers)) {
                return $p;
            }
        }

        return new GenericCsvMappingProfile();
    }
}
