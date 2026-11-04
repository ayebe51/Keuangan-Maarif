<?php

namespace App\Domain\Accounting\DTOs;

use App\Domain\Accounting\Enums\PostingCategory;

class PostingMatrixDefinition
{
    /**
     * @param  array<int, array{role: string, side: string, description?: string}>  $lineRoles
     */
    public function __construct(
        public readonly PostingCategory $category,
        public readonly string $bankDirection,
        public readonly string $debitRole,
        public readonly string $creditRole,
        public readonly ?string $subtype = null,
        public readonly bool $isMultiLine = false,
        public readonly array $lineRoles = [],
        public readonly ?BankTransferContext $transferContext = null,
        public readonly string $pnlImpact = 'STANDARD'
    ) {}

    public function getDebitRole(): string
    {
        return $this->debitRole;
    }

    public function getCreditRole(): string
    {
        return $this->creditRole;
    }

    public function isTransfer(): bool
    {
        return $this->category === PostingCategory::BANK_TRANSFER;
    }

    public function isMultiLine(): bool
    {
        return $this->isMultiLine;
    }

    public function hasSubtype(): bool
    {
        return $this->subtype !== null;
    }

    public function getSubtype(): ?string
    {
        return $this->subtype;
    }

    /**
     * @return array<int, array{role: string, side: string, description?: string}>
     */
    public function getLineRoles(): array
    {
        if (! empty($this->lineRoles)) {
            return $this->lineRoles;
        }

        return [
            ['role' => $this->debitRole, 'side' => 'debit'],
            ['role' => $this->creditRole, 'side' => 'credit'],
        ];
    }

    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'bank_direction' => $this->bankDirection,
            'debit_role' => $this->debitRole,
            'credit_role' => $this->creditRole,
            'subtype' => $this->subtype,
            'is_multi_line' => $this->isMultiLine,
            'line_roles' => $this->getLineRoles(),
            'transfer_context' => $this->transferContext?->toArray(),
            'pnl_impact' => $this->pnlImpact,
        ];
    }
}
