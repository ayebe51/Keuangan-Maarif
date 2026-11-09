<?php

namespace App\Domain\Accounting\DTOs;

use App\Domain\Accounting\ValueObjects\Money;
use InvalidArgumentException;

class JournalLineDTO
{
    public readonly string $debit;

    public readonly string $credit;

    public function __construct(
        public readonly int $accountId,
        string|int|float|Money $debit = '0.00',
        string|int|float|Money $credit = '0.00',
        public readonly ?string $description = null,
        public readonly ?int $bankAccountId = null,
        public readonly ?int $counterpartyId = null,
        public readonly ?int $fundId = null,
        public readonly ?int $receivableId = null
    ) {
        $debitMoney = $debit instanceof Money ? $debit : Money::of($debit);
        $creditMoney = $credit instanceof Money ? $credit : Money::of($credit);

        $this->debit = $debitMoney->getAmount();
        $this->credit = $creditMoney->getAmount();

        $this->validate($debitMoney, $creditMoney);
    }

    private function validate(Money $debitMoney, Money $creditMoney): void
    {
        if ($this->accountId <= 0) {
            throw new InvalidArgumentException("Account ID must be a valid positive integer. Received: {$this->accountId}");
        }

        if ($debitMoney->isNegative() || $creditMoney->isNegative()) {
            throw new InvalidArgumentException("Journal line amounts must be non-negative. Debit: {$this->debit}, Credit: {$this->credit}");
        }

        $hasDebit = $debitMoney->isPositive();
        $hasCredit = $creditMoney->isPositive();

        if (($hasDebit && $hasCredit) || (! $hasDebit && ! $hasCredit)) {
            throw new InvalidArgumentException(
                "Journal line must have exactly one positive side (either debit > 0 or credit > 0). Received Debit: {$this->debit}, Credit: {$this->credit}"
            );
        }
    }

    public function isDebit(): bool
    {
        return Money::of($this->debit)->isPositive();
    }

    public function isCredit(): bool
    {
        return Money::of($this->credit)->isPositive();
    }

    public function getAmount(): Money
    {
        return $this->isDebit() ? Money::of($this->debit) : Money::of($this->credit);
    }

    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'description' => $this->description,
            'bank_account_id' => $this->bankAccountId,
            'counterparty_id' => $this->counterpartyId,
            'fund_id' => $this->fundId,
            'receivable_id' => $this->receivableId,
        ];
    }
}
