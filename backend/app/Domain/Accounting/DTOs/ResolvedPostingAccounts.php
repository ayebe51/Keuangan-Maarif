<?php

namespace App\Domain\Accounting\DTOs;

use App\Domain\Accounting\Enums\PostingCategory;
use App\Domain\Accounting\Models\Account;
use App\Domain\Bank\Models\BankAccount;

class ResolvedPostingAccounts
{
    public function __construct(
        public readonly PostingCategory $category,
        public readonly Account $debitAccount,
        public readonly Account $creditAccount,
        public readonly ?BankAccount $bankAccount = null,
        public readonly ?BankAccount $sourceBankAccount = null,
        public readonly ?BankAccount $destinationBankAccount = null,
        public readonly ?Account $secondaryCreditAccount = null,
        public readonly array $metadata = []
    ) {}

    public function getDebitAccount(): Account
    {
        return $this->debitAccount;
    }

    public function getCreditAccount(): Account
    {
        return $this->creditAccount;
    }

    public function getDebitAccountId(): int
    {
        return (int) $this->debitAccount->id;
    }

    public function getCreditAccountId(): int
    {
        return (int) $this->creditAccount->id;
    }

    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'debit_account_id' => $this->debitAccount->id,
            'debit_account_code' => $this->debitAccount->code,
            'debit_account_name' => $this->debitAccount->name,
            'credit_account_id' => $this->creditAccount->id,
            'credit_account_code' => $this->creditAccount->code,
            'credit_account_name' => $this->creditAccount->name,
            'bank_account_id' => $this->bankAccount?->id,
            'source_bank_account_id' => $this->sourceBankAccount?->id,
            'destination_bank_account_id' => $this->destinationBankAccount?->id,
            'secondary_credit_account_id' => $this->secondaryCreditAccount?->id,
            'metadata' => $this->metadata,
        ];
    }
}
