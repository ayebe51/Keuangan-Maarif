<?php

namespace App\Domain\Bank\Parsers\Profiles;

use App\Domain\Bank\Parsers\Contracts\StatementMappingProfileInterface;

class BriGiroMappingProfile implements StatementMappingProfileInterface
{
    public function name(): string
    {
        return 'bri_giro_v1';
    }

    public function matchHeaders(array $headers): bool
    {
        $normalized = array_map(fn($h) => strtolower(trim((string)$h)), $headers);
        $hasDate = false;
        $hasDesc = false;
        $hasDebet = false;
        $hasKredit = false;

        foreach ($normalized as $h) {
            if (str_contains($h, 'tgl') || str_contains($h, 'tanggal') || str_contains($h, 'date')) $hasDate = true;
            if (str_contains($h, 'ket') || str_contains($h, 'uraian') || str_contains($h, 'deskripsi')) $hasDesc = true;
            if (str_contains($h, 'deb') || str_contains($h, 'keluar')) $hasDebet = true;
            if (str_contains($h, 'kre') || str_contains($h, 'masuk')) $hasKredit = true;
        }

        return $hasDate && ($hasDesc || true) && ($hasDebet || $hasKredit);
    }

    public function mapRow(array $row, array $headers = []): array
    {
        // Default column indices for typical BRI Giro:
        // Col 0: Tanggal | Col 1: Keterangan | Col 2: Debet | Col 3: Kredit | Col 4: Saldo | Col 5: Ref
        $date = null;
        $description = null;
        $debit = null;
        $credit = null;
        $balance = null;
        $reference = null;

        // If named headers exist
        if (!empty($headers)) {
            foreach ($headers as $idx => $header) {
                $h = strtolower(trim((string)$header));
                $val = $row[$idx] ?? ($row[$header] ?? null);

                if (str_contains($h, 'tgl') || str_contains($h, 'tanggal') || str_contains($h, 'date')) {
                    $date = $val;
                } elseif (str_contains($h, 'ref') || str_contains($h, 'bukti') || str_contains($h, 'no_rek')) {
                    $reference = $val;
                } elseif (str_contains($h, 'ket') || str_contains($h, 'uraian') || str_contains($h, 'transaksi') || str_contains($h, 'deskripsi')) {
                    $description = $val;
                } elseif (str_contains($h, 'deb') || str_contains($h, 'pengeluaran') || str_contains($h, 'keluar')) {
                    $debit = $val;
                } elseif (str_contains($h, 'kre') || str_contains($h, 'penerimaan') || str_contains($h, 'masuk')) {
                    $credit = $val;
                } elseif (str_contains($h, 'saldo') || str_contains($h, 'balance')) {
                    $balance = $val;
                }
            }
        }

        // Positional fallback if indices are numeric:
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
