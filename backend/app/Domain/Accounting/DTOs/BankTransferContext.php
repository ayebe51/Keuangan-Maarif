<?php

namespace App\Domain\Accounting\DTOs;

use App\Domain\Accounting\Exceptions\InvalidBankTransferContextException;

class BankTransferContext
{
    public function __construct(
        public readonly int $sourceBankAccountId,
        public readonly int $destinationBankAccountId,
        public readonly ?int $sourceTransactionId = null,
        public readonly ?int $destinationTransactionId = null,
        public readonly ?string $amount = null,
        public readonly string $matchingStatus = 'MATCHED',
        public readonly string $pnlImpact = '0.00'
    ) {
        $this->validate();
    }

    public function validate(): void
    {
        if ($this->sourceBankAccountId <= 0 || $this->destinationBankAccountId <= 0) {
            throw new InvalidBankTransferContextException(
                "Source bank account ID ({$this->sourceBankAccountId}) and destination bank account ID ({$this->destinationBankAccountId}) must be valid positive integers."
            );
        }

        if ($this->sourceBankAccountId === $this->destinationBankAccountId) {
            throw new InvalidBankTransferContextException(
                "Internal bank transfer source and destination bank accounts cannot be identical (Account ID: {$this->sourceBankAccountId})."
            );
        }
    }

    public function toArray(): array
    {
        return [
            'source_bank_account_id' => $this->sourceBankAccountId,
            'destination_bank_account_id' => $this->destinationBankAccountId,
            'source_transaction_id' => $this->sourceTransactionId,
            'destination_transaction_id' => $this->destinationTransactionId,
            'amount' => $this->amount,
            'matching_status' => $this->matchingStatus,
            'pnl_impact' => $this->pnlImpact,
        ];
    }
}
