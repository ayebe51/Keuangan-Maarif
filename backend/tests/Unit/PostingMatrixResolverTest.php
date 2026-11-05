<?php

namespace Tests\Unit;

use App\Domain\Accounting\DTOs\BankTransferContext;
use App\Domain\Accounting\Enums\PostingCategory;
use App\Domain\Accounting\Exceptions\AmbiguousAccountMappingException;
use App\Domain\Accounting\Exceptions\InvalidBankTransferContextException;
use App\Domain\Accounting\Exceptions\InvalidTransactionDirectionException;
use App\Domain\Accounting\Services\PostingMatrixResolver;
use PHPUnit\Framework\TestCase;

class PostingMatrixResolverTest extends TestCase
{
    private PostingMatrixResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PostingMatrixResolver;
    }

    public function test_receivable_payment_mapping(): void
    {
        $def = $this->resolver->resolve(PostingCategory::RECEIVABLE_PAYMENT, 'IN');

        $this->assertSame('BANK', $def->getDebitRole());
        $this->assertSame('RECEIVABLE', $def->getCreditRole());
        $this->assertSame('IN', $def->bankDirection);
        $this->assertFalse($def->isMultiLine());
        $this->assertSame('ZERO', $def->pnlImpact);
    }

    public function test_receivable_payment_multi_line_overpayment_mapping(): void
    {
        $def = $this->resolver->resolve(PostingCategory::RECEIVABLE_PAYMENT, 'IN', [
            'is_overpayment' => true,
        ]);

        $this->assertSame('BANK', $def->getDebitRole());
        $this->assertSame('RECEIVABLE', $def->getCreditRole());
        $this->assertTrue($def->isMultiLine());
        $this->assertSame('OVERPAYMENT', $def->getSubtype());

        $lineRoles = $def->getLineRoles();
        $this->assertCount(3, $lineRoles);
        $this->assertSame('BANK', $lineRoles[0]['role']);
        $this->assertSame('debit', $lineRoles[0]['side']);
        $this->assertSame('RECEIVABLE', $lineRoles[1]['role']);
        $this->assertSame('credit', $lineRoles[1]['side']);
        $this->assertSame('UNAPPLIED_CASH', $lineRoles[2]['role']);
        $this->assertSame('credit', $lineRoles[2]['side']);
    }

    public function test_direct_income_mapping(): void
    {
        $def = $this->resolver->resolve(PostingCategory::DIRECT_INCOME, 'IN');

        $this->assertSame('BANK', $def->getDebitRole());
        $this->assertSame('REVENUE', $def->getCreditRole());
        $this->assertSame('IN', $def->bankDirection);
        $this->assertSame('CREDIT_REVENUE', $def->pnlImpact);
    }

    public function test_bank_interest_mapping(): void
    {
        $def = $this->resolver->resolve(PostingCategory::BANK_INTEREST, 'IN');

        $this->assertSame('BANK', $def->getDebitRole());
        $this->assertSame('INTEREST_REVENUE', $def->getCreditRole());
        $this->assertSame('IN', $def->bankDirection);
        $this->assertSame('CREDIT_REVENUE', $def->pnlImpact);
    }

    public function test_bank_fee_tax_administrative_fee_mapping(): void
    {
        $def = $this->resolver->resolve(PostingCategory::BANK_FEE_TAX, 'OUT', [
            'subtype' => 'ADMIN_FEE',
        ]);

        $this->assertSame('BANK_FEE_EXPENSE', $def->getDebitRole());
        $this->assertSame('BANK', $def->getCreditRole());
        $this->assertSame('ADMIN_FEE', $def->getSubtype());
        $this->assertSame('DEBIT_EXPENSE', $def->pnlImpact);
    }

    public function test_bank_fee_tax_from_category_code_out_adm_bank(): void
    {
        $def = $this->resolver->resolve(PostingCategory::BANK_FEE_TAX, 'OUT', [
            'category_code' => 'OUT-ADM-BANK',
        ]);

        $this->assertSame('BANK_FEE_EXPENSE', $def->getDebitRole());
        $this->assertSame('BANK', $def->getCreditRole());
        $this->assertSame('ADMIN_FEE', $def->getSubtype());
    }

    public function test_bank_fee_tax_tax_expense_mapping(): void
    {
        $def = $this->resolver->resolve(PostingCategory::BANK_FEE_TAX, 'OUT', [
            'subtype' => 'TAX',
        ]);

        $this->assertSame('TAX_EXPENSE', $def->getDebitRole());
        $this->assertSame('BANK', $def->getCreditRole());
        $this->assertSame('TAX', $def->getSubtype());
        $this->assertSame('DEBIT_EXPENSE', $def->pnlImpact);
    }

    public function test_bank_fee_tax_from_category_code_out_pajak_bunga(): void
    {
        $def = $this->resolver->resolve(PostingCategory::BANK_FEE_TAX, 'OUT', [
            'category_code' => 'OUT-PAJAK-BUNGA',
        ]);

        $this->assertSame('TAX_EXPENSE', $def->getDebitRole());
        $this->assertSame('BANK', $def->getCreditRole());
        $this->assertSame('TAX', $def->getSubtype());
    }

    public function test_bank_fee_tax_ambiguous_subtype_rejected_fail_closed(): void
    {
        $this->expectException(AmbiguousAccountMappingException::class);
        $this->resolver->resolve(PostingCategory::BANK_FEE_TAX, 'OUT', []);
    }

    public function test_operating_expense_mapping(): void
    {
        $def = $this->resolver->resolve(PostingCategory::OPERATING_EXPENSE, 'OUT');

        $this->assertSame('OPERATING_EXPENSE', $def->getDebitRole());
        $this->assertSame('BANK', $def->getCreditRole());
        $this->assertSame('OUT', $def->bankDirection);
        $this->assertSame('DEBIT_EXPENSE', $def->pnlImpact);
    }

    public function test_allocation_disbursement_mapping(): void
    {
        $def = $this->resolver->resolve(PostingCategory::ALLOCATION_DISBURSEMENT, 'OUT');

        $this->assertSame('FUND_ALLOCATION', $def->getDebitRole());
        $this->assertSame('BANK', $def->getCreditRole());
        $this->assertSame('OUT', $def->bankDirection);
        $this->assertSame('DEBIT_LIABILITY', $def->pnlImpact);
    }

    public function test_bank_transfer_mapping_with_valid_paired_context(): void
    {
        $transferContext = new BankTransferContext(
            sourceBankAccountId: 1,
            destinationBankAccountId: 2,
            sourceTransactionId: 101,
            destinationTransactionId: 102,
            amount: '5000000.00'
        );

        $def = $this->resolver->resolve(PostingCategory::BANK_TRANSFER, 'TRANSFER', [
            'transfer_context' => $transferContext,
        ]);

        $this->assertTrue($def->isTransfer());
        $this->assertSame('DESTINATION_BANK', $def->getDebitRole());
        $this->assertSame('SOURCE_BANK', $def->getCreditRole());
        $this->assertSame('ZERO', $def->pnlImpact);
        $this->assertNotNull($def->transferContext);
        $this->assertSame(1, $def->transferContext->sourceBankAccountId);
        $this->assertSame(2, $def->transferContext->destinationBankAccountId);
    }

    public function test_bank_transfer_without_context_rejected(): void
    {
        $this->expectException(InvalidBankTransferContextException::class);
        $this->resolver->resolve(PostingCategory::BANK_TRANSFER, 'TRANSFER', []);
    }

    public function test_bank_transfer_with_identical_source_and_destination_rejected(): void
    {
        $this->expectException(InvalidBankTransferContextException::class);
        new BankTransferContext(
            sourceBankAccountId: 1,
            destinationBankAccountId: 1
        );
    }

    public function test_unapplied_cash_mapping(): void
    {
        $def = $this->resolver->resolve(PostingCategory::UNAPPLIED_CASH, 'IN');

        $this->assertSame('BANK', $def->getDebitRole());
        $this->assertSame('UNAPPLIED_CASH', $def->getCreditRole());
        $this->assertSame('IN', $def->bankDirection);
        $this->assertSame('CREDIT_LIABILITY', $def->pnlImpact);
    }

    public function test_invalid_bank_direction_rejected(): void
    {
        $this->expectException(InvalidTransactionDirectionException::class);
        // RECEIVABLE_PAYMENT requires IN, OUT must be rejected
        $this->resolver->resolve(PostingCategory::RECEIVABLE_PAYMENT, 'OUT');
    }

    public function test_operating_expense_with_direction_in_rejected(): void
    {
        $this->expectException(InvalidTransactionDirectionException::class);
        // OPERATING_EXPENSE requires OUT, IN must be rejected
        $this->resolver->resolve(PostingCategory::OPERATING_EXPENSE, 'IN');
    }
}
