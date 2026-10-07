<?php

namespace App\Domain\Bank\Parsers\Profiles;

use App\Domain\Bank\Parsers\Contracts\StatementMappingProfileInterface;

class BukuKasMappingProfile implements StatementMappingProfileInterface
{
    public function name(): string
    {
        return 'buku_kas_v1';
    }

    public function matchHeaders(array $headers): bool
    {
        $normalized = array_map(fn($h) => strtolower(trim((string)$h)), $headers);
        $hasPenerimaan = false;
        $hasPengeluaran = false;

        foreach ($normalized as $h) {
            if (str_contains($h, 'penerimaan')) $hasPenerimaan = true;
            if (str_contains($h, 'pengeluaran')) $hasPengeluaran = true;
        }

        return $hasPenerimaan || $hasPengeluaran;
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
                } elseif (str_contains($h, 'bukti') || str_contains($h, 'no_bukti') || str_contains($h, 'ref')) {
                    $reference = $val;
                } elseif (str_contains($h, 'uraian') || str_contains($h, 'ket') || str_contains($h, 'keterangan')) {
                    $description = $val;
                } elseif (str_contains($h, 'pengeluaran') || str_contains($h, 'deb')) {
                    $debit = $val; // Pengeluaran = OUT / Debet
                } elseif (str_contains($h, 'penerimaan') || str_contains($h, 'kre')) {
                    $credit = $val; // Penerimaan = IN / Kredit
                } elseif (str_contains($h, 'saldo')) {
                    $balance = $val;
                }
            }
        }

        // Positional fallback:
        // Col 0: Tanggal | Col 1: No Bukti | Col 2: Uraian | Col 3: Penerimaan | Col 4: Pengeluaran | Col 5: Saldo
        $indexed = array_values($row);
        if ($date === null && isset($indexed[0])) $date = $indexed[0];
        if ($reference === null && isset($indexed[1])) $reference = $indexed[1];
        if ($description === null && isset($indexed[2])) $description = $indexed[2];
        if ($credit === null && isset($indexed[3])) $credit = $indexed[3];
        if ($debit === null && isset($indexed[4])) $debit = $indexed[4];
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
