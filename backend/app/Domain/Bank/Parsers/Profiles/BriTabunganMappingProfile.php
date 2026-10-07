<?php

namespace App\Domain\Bank\Parsers\Profiles;

use App\Domain\Bank\Parsers\Contracts\StatementMappingProfileInterface;

class BriTabunganMappingProfile implements StatementMappingProfileInterface
{
    public function name(): string
    {
        return 'bri_tabungan_v1';
    }

    public function matchHeaders(array $headers): bool
    {
        $normalized = array_map(fn($h) => strtolower(trim((string)$h)), $headers);
        $hasNo = false;
        $hasDate = false;

        foreach ($normalized as $h) {
            if ($h === 'no' || $h === 'no.' || str_contains($h, 'nomor')) $hasNo = true;
            if (str_contains($h, 'tgl') || str_contains($h, 'tanggal')) $hasDate = true;
        }

        return $hasNo && $hasDate;
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

                if (str_contains($h, 'tgl') || str_contains($h, 'tanggal') || str_contains($h, 'date')) {
                    $date = $val;
                } elseif (str_contains($h, 'ket') || str_contains($h, 'uraian') || str_contains($h, 'transaksi')) {
                    $description = $val;
                } elseif (str_contains($h, 'deb') || str_contains($h, 'pengeluaran') || str_contains($h, 'keluar')) {
                    $debit = $val;
                } elseif (str_contains($h, 'kre') || str_contains($h, 'penerimaan') || str_contains($h, 'masuk')) {
                    $credit = $val;
                } elseif (str_contains($h, 'saldo') || str_contains($h, 'balance')) {
                    $balance = $val;
                } elseif (str_contains($h, 'ref') || str_contains($h, 'bukti')) {
                    $reference = $val;
                }
            }
        }

        // Positional fallback when No is at 0:
        $indexed = array_values($row);
        if ($date === null && isset($indexed[1])) $date = $indexed[1];
        if ($description === null && isset($indexed[2])) $description = $indexed[2];
        if ($debit === null && isset($indexed[3])) $debit = $indexed[3];
        if ($credit === null && isset($indexed[4])) $credit = $indexed[4];
        if ($balance === null && isset($indexed[5])) $balance = $indexed[5];

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
