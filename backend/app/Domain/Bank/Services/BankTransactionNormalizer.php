<?php

namespace App\Domain\Bank\Services;

use App\Domain\Bank\Enums\BankTransactionDirection;
use App\Domain\Counterparty\Services\CounterpartyService;
use DateTimeImmutable;
use InvalidArgumentException;

class BankTransactionNormalizer
{
    public function __construct(
        protected CounterpartyService $counterpartyService
    ) {}

    /**
     * Normalize a raw parsed statement row into structured transaction attributes.
     *
     * @param int $organizationId
     * @param int $bankAccountId
     * @param array $rawRow
     * @param int $rowSequence
     * @return array{
     *     date: string,
     *     direction: BankTransactionDirection,
     *     amount: string,
     *     balance_after: ?string,
     *     description: string,
     *     reference_number: ?string,
     *     raw_counterparty_name: ?string,
     *     counterparty_id: ?int,
     *     fingerprint: string
     * }
     */
    public function normalize(int $organizationId, int $bankAccountId, array $rawRow, int $rowSequence = 1): array
    {
        $normalizedDate = $this->normalizeDate($rawRow['date_raw'] ?? null);
        if ($normalizedDate === null) {
            throw new InvalidArgumentException("Unable to parse transaction date from raw value: " . json_encode($rawRow['date_raw'] ?? ''));
        }

        $debit = $this->parseAmount($rawRow['debit_raw'] ?? null);
        $credit = $this->parseAmount($rawRow['credit_raw'] ?? null);
        $balance = $this->parseAmount($rawRow['balance_raw'] ?? null, allowZero: true);

        // BANK SEMANTICS INVARIANT:
        // Credit > 0 => IN (Uang Masuk / Kas Bertambah)
        // Debit > 0  => OUT (Uang Keluar / Kas Berkurang)
        $creditVal = (float)$credit;
        $debitVal = (float)$debit;

        if ($creditVal > 0 && $debitVal == 0) {
            $direction = BankTransactionDirection::IN;
            $amount = $credit;
        } elseif ($debitVal > 0 && $creditVal == 0) {
            $direction = BankTransactionDirection::OUT;
            $amount = $debit;
        } elseif ($creditVal > 0 && $debitVal > 0) {
            // Netting if both provided: whichever is greater
            if ($creditVal >= $debitVal) {
                $direction = BankTransactionDirection::IN;
                $amount = number_format($creditVal - $debitVal, 2, '.', '');
            } else {
                $direction = BankTransactionDirection::OUT;
                $amount = number_format($debitVal - $creditVal, 2, '.', '');
            }
        } else {
            throw new InvalidArgumentException("Transaction row has neither valid debit nor credit amount.");
        }

        $rawDesc = trim((string)($rawRow['desc_raw'] ?? ''));
        $cleanDesc = strtoupper(preg_replace('/\s+/', ' ', $rawDesc));

        $rawRef = isset($rawRow['ref_raw']) ? trim((string)$rawRow['ref_raw']) : null;
        if ($rawRef === '') $rawRef = null;

        // Auto-resolve counterparty
        $counterparty = $this->counterpartyService->resolve($organizationId, $cleanDesc);
        $counterpartyId = $counterparty?->id;
        $rawCounterpartyName = $counterparty?->name;

        // Generate collision-free fingerprint with row sequence (REV3-06)
        $fingerprint = FingerprintGenerator::generate(
            organizationId: $organizationId,
            bankAccountId: $bankAccountId,
            normalizedDate: $normalizedDate,
            rowSequence: $rowSequence,
            direction: $direction->value,
            amountDecimal: $amount,
            normalizedDescription: $cleanDesc
        );

        return [
            'date' => $normalizedDate,
            'direction' => $direction,
            'amount' => $amount,
            'balance_after' => $balance !== '0.00' ? $balance : null,
            'description' => $cleanDesc,
            'reference_number' => $rawRef,
            'raw_counterparty_name' => $rawCounterpartyName,
            'counterparty_id' => $counterpartyId,
            'fingerprint' => $fingerprint,
        ];
    }

    public function normalizeDate(mixed $dateRaw): ?string
    {
        if ($dateRaw === null || trim((string)$dateRaw) === '') {
            return null;
        }

        $str = trim((string)$dateRaw);

        // 1. Check ISO Y-m-d
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
            return $str;
        }

        // 2. Check numeric Excel serial date
        if (is_numeric($str) && (float)$str > 30000 && (float)$str < 70000) {
            $days = (int)$str;
            $seconds = ($days - 25569) * 86400;
            return gmdate('Y-m-d', $seconds);
        }

        // 3. Common Indonesian month names
        $idMonths = [
            'januari' => '01', 'jan' => '01',
            'februari' => '02', 'feb' => '02',
            'maret' => '03', 'mar' => '03',
            'april' => '04', 'apr' => '04',
            'mei' => '05', 'may' => '05',
            'juni' => '06', 'jun' => '06',
            'juli' => '07', 'jul' => '07',
            'agustus' => '08', 'agu' => '08', 'agt' => '08',
            'september' => '09', 'sep' => '09',
            'oktober' => '10', 'okt' => '10',
            'november' => '11', 'nov' => '11',
            'desember' => '12', 'des' => '12',
        ];

        $lower = strtolower($str);
        foreach ($idMonths as $mName => $mNum) {
            if (str_contains($lower, $mName)) {
                // e.g. "01 Maret 2026" or "1 Mar 2026"
                if (preg_match('/(\d{1,2})\s+[a-z]+\s+(\d{4})/i', $str, $matches)) {
                    $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $year = $matches[2];
                    return "{$year}-{$mNum}-{$day}";
                }
            }
        }

        // 4. Formats like dd/mm/yyyy or dd-mm-yyyy or mm/dd/yyyy
        if (preg_match('/^(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{4})$/', $str, $matches)) {
            $part1 = (int)$matches[1];
            $part2 = (int)$matches[2];
            $year = $matches[3];

            // Default assumption is dd/mm/yyyy
            $day = str_pad((string)$part1, 2, '0', STR_PAD_LEFT);
            $month = str_pad((string)$part2, 2, '0', STR_PAD_LEFT);

            // If part1 > 12, it must be day
            if ($part1 <= 12 && $part2 > 12) {
                // mm/dd/yyyy
                $month = str_pad((string)$part1, 2, '0', STR_PAD_LEFT);
                $day = str_pad((string)$part2, 2, '0', STR_PAD_LEFT);
            }

            return "{$year}-{$month}-{$day}";
        }

        // 5. Try standard PHP DateTimeImmutable
        try {
            $dt = new DateTimeImmutable($str);
            return $dt->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    public function parseAmount(mixed $val, bool $allowZero = false): string
    {
        if ($val === null) {
            return '0.00';
        }

        $str = trim((string)$val);
        if ($str === '' || $str === '-' || $str === '--') {
            return '0.00';
        }

        // Remove currency symbols, parentheses, and spaces
        $str = preg_replace('/[Rp\s]/i', '', $str);

        // Check if negative in parentheses e.g. "(5.539,00)"
        if (str_starts_with($str, '(') && str_ends_with($str, ')')) {
            $str = substr($str, 1, -1);
        }

        // Format detection:
        // Case A: Indonesian format: 124.258.949,00 or 5.539,00
        // (Has commas as decimal separator, dots as thousand separator)
        if (str_contains($str, ',') && str_contains($str, '.')) {
            // Check which comes last
            $lastComma = strrpos($str, ',');
            $lastDot = strrpos($str, '.');
            if ($lastComma > $lastDot) {
                // Indonesian format: dot = thousand, comma = decimal
                $str = str_replace('.', '', $str);
                $str = str_replace(',', '.', $str);
            } else {
                // US format: comma = thousand, dot = decimal
                $str = str_replace(',', '', $str);
            }
        } elseif (str_contains($str, ',')) {
            // Only comma: e.g. "5539,00" or "852000,00"
            // If comma is followed by 1 or 2 digits, it's decimal
            if (preg_match('/,\d{1,2}$/', $str)) {
                $str = str_replace(',', '.', $str);
            } else {
                // Comma as thousands: "852,000"
                $str = str_replace(',', '', $str);
            }
        } elseif (str_contains($str, '.')) {
            // Only dots: e.g. "9.077.695" or "852.000" or "5539.00"
            // If multiple dots, they must be thousand separators: "9.077.695"
            if (substr_count($str, '.') > 1) {
                $str = str_replace('.', '', $str);
            } elseif (preg_match('/\.\d{3}$/', $str)) {
                // Single dot followed by exactly 3 digits is usually Indonesian thousand: "852.000"
                $str = str_replace('.', '', $str);
            }
        }

        $numericVal = (float)$str;
        if ($numericVal < 0) {
            $numericVal = abs($numericVal);
        }

        return number_format($numericVal, 2, '.', '');
    }
}
