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
