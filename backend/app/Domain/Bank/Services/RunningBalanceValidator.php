<?php

namespace App\Domain\Bank\Services;

use App\Domain\Bank\Enums\BankTransactionDirection;

class RunningBalanceValidator
{
    /**
     * Validate continuity of running balance across normalized statement rows.
     * Sesuai mandat REV3-06:
     * Saldo berjalan divalidasi; mismatch menjadi exception, bukan silent correction.
     *
     * @param array<int, array{
     *     row_index: int,
     *     direction: BankTransactionDirection|string,
     *     amount: string,
     *     balance_after: ?string,
     *     date: string
     * }> $rows
     * @return array<int, array{
     *     row_index: int,
     *     expected_balance: string,
     *     reported_balance: string,
     *     discrepancy: string,
     *     message: string
     * }>
     */
    public function validate(array $rows): array
    {
        $discrepancies = [];
        $prevBalance = null;

        foreach ($rows as $row) {
            $currentBalanceStr = $row['balance_after'] ?? null;
            if ($currentBalanceStr === null) {
                // If this row has no running balance recorded, cannot validate step
                continue;
            }

            $currentBalance = (float)$currentBalanceStr;
            $amount = (float)$row['amount'];
            $isCashIn = ($row['direction'] instanceof BankTransactionDirection)
                ? $row['direction']->isCashIn()
                : strtoupper((string)$row['direction']) === 'IN';

            if ($prevBalance !== null) {
                $expected = $isCashIn ? ($prevBalance + $amount) : ($prevBalance - $amount);
                $diff = abs($currentBalance - $expected);

                // If difference is greater than 1 sen (0.01)
                if ($diff > 0.01) {
                    $discrepancies[] = [
                        'row_index' => $row['row_index'],
                        'expected_balance' => number_format($expected, 2, '.', ''),
                        'reported_balance' => number_format($currentBalance, 2, '.', ''),
                        'discrepancy' => number_format($diff, 2, '.', ''),
                        'message' => sprintf(
                            "Running balance discrepancy at row %d: expected %s, reported %s (diff: %s)",
                            $row['row_index'],
                            number_format($expected, 2, '.', ''),
                            number_format($currentBalance, 2, '.', ''),
                            number_format($diff, 2, '.', '')
                        ),
                    ];
                }
            }

            $prevBalance = $currentBalance;
        }

        return $discrepancies;
    }
}
