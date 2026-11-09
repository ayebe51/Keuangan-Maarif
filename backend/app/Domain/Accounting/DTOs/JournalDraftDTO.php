<?php

namespace App\Domain\Accounting\DTOs;

use App\Domain\Accounting\Enums\PostingCategory;
use App\Domain\Accounting\Exceptions\UnbalancedJournalException;
use App\Domain\Accounting\ValueObjects\Money;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use InvalidArgumentException;

class JournalDraftDTO
{
    /**
     * @param  array<int, JournalLineDTO>  $lines
     * @param  array<int, array{source_type: string, source_id: int, leg_role: string, business_transaction_id?: int}>  $additionalSources
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly int $fiscalPeriodId,
        public readonly string $entryDate,
        public readonly string $entryType,
        public readonly string $description,
        public readonly ?string $reference,
        public readonly BusinessTransaction $sourceTransaction,
        public readonly PostingCategory $postingCategory,
        public readonly array $lines,
        public readonly int $createdBy,
        public readonly string $legRole = 'primary',
        public readonly array $additionalSources = []
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        // 1. Organization tenant boundary
        if ((int) $this->sourceTransaction->organization_id !== $this->organizationId) {
            throw new CrossTenantViolationException(
                "Source transaction organization ({$this->sourceTransaction->organization_id}) does not match draft organization ({$this->organizationId})."
            );
        }

        // 2. Minimum line count
        if (count($this->lines) < 2) {
            throw new InvalidArgumentException(
                'Journal draft must contain at least 2 lines to form a valid double-entry balance. Received: '.count($this->lines)
            );
        }

        // 3. Balance verification via Money Value Object
        $totalDebit = Money::zero();
        $totalCredit = Money::zero();

        foreach ($this->lines as $index => $line) {
            if (! $line instanceof JournalLineDTO) {
                throw new InvalidArgumentException("Line at index {$index} must be an instance of JournalLineDTO.");
            }

            $totalDebit = $totalDebit->add(Money::of($line->debit));
            $totalCredit = $totalCredit->add(Money::of($line->credit));
        }

        if (! $totalDebit->equals($totalCredit)) {
            throw new UnbalancedJournalException(
                $totalDebit->getAmount(),
                $totalCredit->getAmount(),
                "Journal draft lines are unbalanced. Total Debit: {$totalDebit->getAmount()}, Total Credit: {$totalCredit->getAmount()}"
            );
        }

        if ($totalDebit->isZero()) {
            throw new InvalidArgumentException('Total journal draft debit and credit must be greater than zero.');
        }
    }

    public function getTotalAmount(): Money
    {
        $total = Money::zero();
        foreach ($this->lines as $line) {
            if ($line->isDebit()) {
                $total = $total->add(Money::of($line->debit));
            }
        }

        return $total;
    }

    public function toDraftPayload(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'fiscal_period_id' => $this->fiscalPeriodId,
            'entry_date' => $this->entryDate,
            'entry_type' => $this->entryType,
            'reference' => $this->reference,
            'description' => $this->description,
            'lines' => array_map(fn (JournalLineDTO $line) => $line->toArray(), $this->lines),
        ];
    }
}
