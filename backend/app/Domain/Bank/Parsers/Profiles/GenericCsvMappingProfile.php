<?php

namespace App\Domain\Bank\Parsers\Profiles;

use App\Domain\Bank\Parsers\Contracts\StatementMappingProfileInterface;

class GenericCsvMappingProfile implements StatementMappingProfileInterface
{
    public function name(): string
    {
        return 'generic_csv_v1';
    }

    public function matchHeaders(array $headers): bool
    {
        return true; // Fallback profile
    }

    public function mapRow(array $row, array $headers = []): array
    {
        $date = null;
        $description = null;
        $debit = null;
        $credit = null;
        $balance = null;
        $reference = null;

        if (!empty($headers)) {
            foreach ($headers as $idx => $header) {
                $h = strtolower(trim((string)$header));
                $val = $row[$idx] ?? ($row[$header] ?? null);

                if (str_contains($h, 'date') || str_contains($h, 'tgl') || str_contains($h, 'tanggal')) {
                    $date = $val;
                } elseif (str_contains($h, 'desc') || str_contains($h, 'keterangan') || str_contains($h, 'uraian') || str_contains($h, 'transaksi')) {
                    $description = $val;
                } elseif (str_contains($h, 'debit') || str_contains($h, 'debet') || str_contains($h, 'keluar') || str_contains($h, 'pengeluaran')) {
                    $debit = $val;
                } elseif (str_contains($h, 'credit') || str_contains($h, 'kredit') || str_contains($h, 'masuk') || str_contains($h, 'penerimaan')) {
                    $credit = $val;
                } elseif (str_contains($h, 'balance') || str_contains($h, 'saldo')) {
                    $balance = $val;
                } elseif (str_contains($h, 'ref') || str_contains($h, 'bukti') || str_contains($h, 'id')) {
                    $reference = $val;
                }
            }
        }

        $indexed = array_values($row);
        if ($date === null && isset($indexed[0])) $date = $indexed[0];
        if ($description === null && isset($indexed[1])) $description = $indexed[1];
        if ($debit === null && isset($indexed[2])) $debit = $indexed[2];
        if ($credit === null && isset($indexed[3])) $credit = $indexed[3];
        if ($balance === null && isset($indexed[4])) $balance = $indexed[4];

        return [
            'date' => $date,
            'description' => $description,
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $balance,
            'reference' => $reference,
        ];
    }
}
