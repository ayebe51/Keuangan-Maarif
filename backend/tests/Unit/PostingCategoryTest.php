<?php

namespace Tests\Unit;

use App\Domain\Accounting\Enums\PostingCategory;
use App\Domain\Accounting\Exceptions\UnsupportedPostingCategoryException;
use App\Domain\Classification\Enums\BusinessTransactionType;
use PHPUnit\Framework\TestCase;

class PostingCategoryTest extends TestCase
{
    public function test_all_eight_categories_recognized(): void
    {
        $cases = PostingCategory::cases();
        $this->assertCount(8, $cases);

        $expectedValues = [
            'RECEIVABLE_PAYMENT',
            'DIRECT_INCOME',
            'BANK_INTEREST',
            'BANK_FEE_TAX',
            'OPERATING_EXPENSE',
            'ALLOCATION_DISBURSEMENT',
            'BANK_TRANSFER',
            'UNAPPLIED_CASH',
        ];

        $actualValues = array_map(fn (PostingCategory $c) => $c->value, $cases);
        $this->assertSame($expectedValues, $actualValues);
    }

    public function test_no_duplicate_categories(): void
    {
        $values = array_map(fn (PostingCategory $c) => $c->value, PostingCategory::cases());
        $this->assertSame(count($values), count(array_unique($values)));
    }

    public function test_unsupported_category_rejected_fail_closed(): void
    {
        $this->expectException(UnsupportedPostingCategoryException::class);
        PostingCategory::fromTransactionType('UNKNOWN_TRANSACTION_TYPE');
    }

    public function test_mapping_from_existing_business_transaction_type_is_correct(): void
    {
        $this->assertSame(
            PostingCategory::RECEIVABLE_PAYMENT,
            PostingCategory::fromTransactionType(BusinessTransactionType::RECEIVABLE_PAYMENT)
        );
        $this->assertSame(
            PostingCategory::DIRECT_INCOME,
            PostingCategory::fromTransactionType(BusinessTransactionType::DIRECT_INCOME)
        );
        $this->assertSame(
            PostingCategory::BANK_INTEREST,
            PostingCategory::fromTransactionType(BusinessTransactionType::BANK_INTEREST)
        );
        $this->assertSame(
            PostingCategory::BANK_FEE_TAX,
            PostingCategory::fromTransactionType(BusinessTransactionType::BANK_FEE_TAX)
        );
        $this->assertSame(
            PostingCategory::OPERATING_EXPENSE,
            PostingCategory::fromTransactionType(BusinessTransactionType::OPERATING_EXPENSE)
        );
        $this->assertSame(
            PostingCategory::ALLOCATION_DISBURSEMENT,
            PostingCategory::fromTransactionType(BusinessTransactionType::ALLOCATION_DISBURSEMENT)
        );
        $this->assertSame(
            PostingCategory::BANK_TRANSFER,
            PostingCategory::fromTransactionType(BusinessTransactionType::BANK_TRANSFER)
        );
    }

    public function test_mapping_from_raw_string_is_correct(): void
    {
        $this->assertSame(
            PostingCategory::UNAPPLIED_CASH,
            PostingCategory::fromTransactionType('UNAPPLIED_CASH')
        );
        $this->assertSame(
            PostingCategory::DIRECT_INCOME,
            PostingCategory::fromTransactionType('direct_income')
        );
    }

    public function test_allowed_directions_enforced_strictly(): void
    {
        // IN-only categories
        $inCategories = [
            PostingCategory::RECEIVABLE_PAYMENT,
            PostingCategory::DIRECT_INCOME,
            PostingCategory::BANK_INTEREST,
            PostingCategory::UNAPPLIED_CASH,
        ];
        foreach ($inCategories as $cat) {
            $this->assertTrue($cat->isDirectionAllowed('IN'), "Category {$cat->value} must allow IN");
            $this->assertFalse($cat->isDirectionAllowed('OUT'), "Category {$cat->value} must NOT allow OUT");
        }

        // OUT-only categories
        $outCategories = [
            PostingCategory::BANK_FEE_TAX,
            PostingCategory::OPERATING_EXPENSE,
            PostingCategory::ALLOCATION_DISBURSEMENT,
        ];
        foreach ($outCategories as $cat) {
            $this->assertTrue($cat->isDirectionAllowed('OUT'), "Category {$cat->value} must allow OUT");
            $this->assertFalse($cat->isDirectionAllowed('IN'), "Category {$cat->value} must NOT allow IN");
        }

        // Transfer allows IN, OUT, TRANSFER
        $this->assertTrue(PostingCategory::BANK_TRANSFER->isDirectionAllowed('IN'));
        $this->assertTrue(PostingCategory::BANK_TRANSFER->isDirectionAllowed('OUT'));
        $this->assertTrue(PostingCategory::BANK_TRANSFER->isDirectionAllowed('TRANSFER'));
    }

    public function test_category_labels_are_present_and_descriptive(): void
    {
        foreach (PostingCategory::cases() as $cat) {
            $label = $cat->label();
            $this->assertNotEmpty($label);
            $this->assertIsString($label);
        }
    }
}
