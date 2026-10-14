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
