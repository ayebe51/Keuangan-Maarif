<?php

namespace Tests\Unit;

use App\Domain\Accounting\DTOs\JournalDraftDTO;
use App\Domain\Accounting\DTOs\JournalLineDTO;
use App\Domain\Accounting\Enums\PostingCategory;
use App\Domain\Accounting\Exceptions\UnbalancedJournalException;
use App\Domain\Accounting\ValueObjects\Money;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class JournalDraftDTOTest extends TestCase
{
    private function createDummyTransaction(int $organizationId = 1): BusinessTransaction
    {
        $tx = new BusinessTransaction;
        $tx->id = 100;
        $tx->organization_id = $organizationId;
        $tx->transaction_number = 'BT-2026-00001';
        $tx->transaction_date = '2026-03-15';
        $tx->amount = '1000000.00';
        $tx->direction = 'IN';

        return $tx;
    }

    public function test_valid_two_line_dto_creates_payload(): void
    {
        $tx = $this->createDummyTransaction(1);

        $line1 = new JournalLineDTO(accountId: 1110, debit: '1000000.00', credit: '0.00', bankAccountId: 1);
        $line2 = new JournalLineDTO(accountId: 4100, debit: '0.00', credit: '1000000.00');

        $dto = new JournalDraftDTO(
            organizationId: 1,
            fiscalPeriodId: 10,
            entryDate: '2026-03-15',
            entryType: 'receipt',
            description: 'Penerimaan Sumbangan',
            reference: 'BT-2026-00001',
            sourceTransaction: $tx,
            postingCategory: PostingCategory::DIRECT_INCOME,
            lines: [$line1, $line2],
            createdBy: 5
        );

        $payload = $dto->toDraftPayload();

        $this->assertSame(1, $payload['organization_id']);
        $this->assertSame(10, $payload['fiscal_period_id']);
        $this->assertSame('receipt', $payload['entry_type']);
        $this->assertCount(2, $payload['lines']);
        $this->assertSame('1000000.00', $dto->getTotalAmount()->getAmount());
    }

    public function test_valid_three_line_dto_overpayment(): void
    {
        $tx = $this->createDummyTransaction(1);

        $line1 = new JournalLineDTO(accountId: 1120, debit: '1000000.00', credit: '0.00');
        $line2 = new JournalLineDTO(accountId: 1210, debit: '0.00', credit: '852000.00');
        $line3 = new JournalLineDTO(accountId: 2200, debit: '0.00', credit: '148000.00');

        $dto = new JournalDraftDTO(
            organizationId: 1,
            fiscalPeriodId: 10,
            entryDate: '2026-03-15',
            entryType: 'receipt',
            description: 'Pelunasan dan Titipan',
            reference: 'BT-2026-00001',
            sourceTransaction: $tx,
            postingCategory: PostingCategory::RECEIVABLE_PAYMENT,
            lines: [$line1, $line2, $line3],
            createdBy: 5
        );

        $this->assertSame('1000000.00', $dto->getTotalAmount()->getAmount());
        $this->assertCount(3, $dto->lines);
    }

    public function test_unbalanced_lines_throws_unbalanced_journal_exception(): void
    {
        $tx = $this->createDummyTransaction(1);

        $line1 = new JournalLineDTO(accountId: 1110, debit: '1000000.00', credit: '0.00');
        $line2 = new JournalLineDTO(accountId: 4100, debit: '0.00', credit: '999999.00'); // Off by 1.00

        $this->expectException(UnbalancedJournalException::class);
        new JournalDraftDTO(
            organizationId: 1,
            fiscalPeriodId: 10,
            entryDate: '2026-03-15',
            entryType: 'receipt',
            description: 'Unbalanced draft',
            reference: 'BT-2026-00001',
            sourceTransaction: $tx,
            postingCategory: PostingCategory::DIRECT_INCOME,
            lines: [$line1, $line2],
            createdBy: 5
        );
    }

    public function test_less_than_two_lines_rejected(): void
    {
        $tx = $this->createDummyTransaction(1);

        $line1 = new JournalLineDTO(accountId: 1110, debit: '1000000.00', credit: '0.00');

        $this->expectException(InvalidArgumentException::class);
        new JournalDraftDTO(
            organizationId: 1,
            fiscalPeriodId: 10,
            entryDate: '2026-03-15',
            entryType: 'receipt',
            description: 'Single line draft',
            reference: 'BT-2026-00001',
            sourceTransaction: $tx,
            postingCategory: PostingCategory::DIRECT_INCOME,
            lines: [$line1],
            createdBy: 5
        );
    }

    public function test_cross_tenant_source_transaction_rejected(): void
    {
        $tx = $this->createDummyTransaction(organizationId: 2); // Org 2

        $line1 = new JournalLineDTO(accountId: 1110, debit: '1000000.00', credit: '0.00');
        $line2 = new JournalLineDTO(accountId: 4100, debit: '0.00', credit: '1000000.00');

        $this->expectException(CrossTenantViolationException::class);
        new JournalDraftDTO(
            organizationId: 1, // Org 1 context
            fiscalPeriodId: 10,
            entryDate: '2026-03-15',
            entryType: 'receipt',
            description: 'Cross tenant attempt',
            reference: 'BT-2026-00001',
            sourceTransaction: $tx,
            postingCategory: PostingCategory::DIRECT_INCOME,
            lines: [$line1, $line2],
            createdBy: 5
        );
    }

    public function test_journal_line_with_both_debit_and_credit_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new JournalLineDTO(accountId: 1110, debit: '500.00', credit: '500.00');
    }

    public function test_journal_line_with_both_zero_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new JournalLineDTO(accountId: 1110, debit: '0.00', credit: '0.00');
    }

    public function test_journal_line_with_negative_amount_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new JournalLineDTO(accountId: 1110, debit: '-500.00', credit: '0.00');
    }

    public function test_journal_line_with_invalid_account_id_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new JournalLineDTO(accountId: 0, debit: '500.00', credit: '0.00');
    }

    public function test_money_object_normalization_in_lines(): void
    {
        $line = new JournalLineDTO(accountId: 1110, debit: Money::of('750000.00'), credit: Money::zero());
        $this->assertSame('750000.00', $line->debit);
        $this->assertSame('0.00', $line->credit);
        $this->assertTrue($line->isDebit());
        $this->assertFalse($line->isCredit());
    }
}
