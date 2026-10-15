<?php

namespace App\Domain\Bank\Services;

class FingerprintGenerator
{
    /**
     * Generate deterministic SHA-256 fingerprint for a bank transaction row.
     * Sesuai mandat spesifikasi REV3-06:
     * organization + bank_account + statement_date + row_sequence + direction + amount + normalized_description
     */
    public static function generate(
        int $organizationId,
        int $bankAccountId,
        string $normalizedDate,
        int $rowSequence,
        string $direction,
        string $amountDecimal,
        string $normalizedDescription
    ): string {
        $cleanDesc = strtoupper(preg_replace('/\s+/', ' ', trim($normalizedDescription)));
        $cleanAmount = number_format((float) str_replace(',', '', $amountDecimal), 2, '.', '');

        $payload = implode('|', [
            $organizationId,
            $bankAccountId,
            $normalizedDate,
            $rowSequence,
            strtoupper($direction),
            $cleanAmount,
            $cleanDesc,
        ]);

        return hash('sha256', $payload);
    }
}
