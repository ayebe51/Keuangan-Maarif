<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DTOs\BankTransferContext;
use App\Domain\Accounting\DTOs\PostingMatrixDefinition;
use App\Domain\Accounting\Enums\PostingCategory;
use App\Domain\Accounting\Exceptions\AmbiguousAccountMappingException;
use App\Domain\Accounting\Exceptions\InvalidBankTransferContextException;
use App\Domain\Accounting\Exceptions\InvalidTransactionDirectionException;

class PostingMatrixResolver
{
    /**
     * Resolves a PostingCategory and transaction direction into a double-entry matrix definition.
     * Pure domain resolver: Does not modify database or create journal entries.
     *
     * @param array{
     *     subtype?: string|null,
     *     category_code?: string|null,
     *     is_overpayment?: bool,
     *     transfer_context?: BankTransferContext|null,
     *     source_bank_account_id?: int|null,
     *     destination_bank_account_id?: int|null,
     *     source_transaction_id?: int|null,
     *     destination_transaction_id?: int|null,
     *     amount?: string|null
     * } $context
     */
    public function resolve(PostingCategory $category, string $direction, array $context = []): PostingMatrixDefinition
    {
        $normalizedDirection = strtoupper(trim($direction));

        // 1. Direction Validation (Fail-closed)
        if (! $category->isDirectionAllowed($normalizedDirection)) {
            throw new InvalidTransactionDirectionException($category->value, $normalizedDirection);
        }

        // 2. Resolve structural matrix based on PostingCategory
        return match ($category) {
            PostingCategory::RECEIVABLE_PAYMENT => $this->resolveReceivablePayment($normalizedDirection, $context),
            PostingCategory::DIRECT_INCOME => $this->resolveDirectIncome($normalizedDirection, $context),
            PostingCategory::BANK_INTEREST => $this->resolveBankInterest($normalizedDirection, $context),
            PostingCategory::BANK_FEE_TAX => $this->resolveBankFeeTax($normalizedDirection, $context),
            PostingCategory::OPERATING_EXPENSE => $this->resolveOperatingExpense($normalizedDirection, $context),
            PostingCategory::ALLOCATION_DISBURSEMENT => $this->resolveAllocationDisbursement($normalizedDirection, $context),
            PostingCategory::BANK_TRANSFER => $this->resolveBankTransfer($normalizedDirection, $context),
            PostingCategory::UNAPPLIED_CASH => $this->resolveUnappliedCash($normalizedDirection, $context),
        };
    }

    private function resolveReceivablePayment(string $direction, array $context): PostingMatrixDefinition
    {
        $isOverpayment = ! empty($context['is_overpayment']);

        if ($isOverpayment) {
            // Multi-line layout: Debit BANK, Credit RECEIVABLE, Credit UNAPPLIED_CASH
            $lineRoles = [
                ['role' => 'BANK', 'side' => 'debit', 'description' => 'Penerimaan Kas/Bank dari Pelunasan Piutang'],
                ['role' => 'RECEIVABLE', 'side' => 'credit', 'description' => 'Pelunasan Pokok Piutang Madrasah'],
                ['role' => 'UNAPPLIED_CASH', 'side' => 'credit', 'description' => 'Kelebihan Pembayaran (Titipan Kas)'],
            ];

            return new PostingMatrixDefinition(
                category: PostingCategory::RECEIVABLE_PAYMENT,
                bankDirection: $direction,
                debitRole: 'BANK',
                creditRole: 'RECEIVABLE',
                subtype: 'OVERPAYMENT',
                isMultiLine: true,
                lineRoles: $lineRoles,
                pnlImpact: 'ZERO'
            );
        }

        return new PostingMatrixDefinition(
            category: PostingCategory::RECEIVABLE_PAYMENT,
            bankDirection: $direction,
            debitRole: 'BANK',
            creditRole: 'RECEIVABLE',
            pnlImpact: 'ZERO'
        );
    }

    private function resolveDirectIncome(string $direction, array $context): PostingMatrixDefinition
    {
        return new PostingMatrixDefinition(
            category: PostingCategory::DIRECT_INCOME,
            bankDirection: $direction,
            debitRole: 'BANK',
            creditRole: 'REVENUE',
            pnlImpact: 'CREDIT_REVENUE'
        );
    }

    private function resolveBankInterest(string $direction, array $context): PostingMatrixDefinition
    {
        return new PostingMatrixDefinition(
            category: PostingCategory::BANK_INTEREST,
            bankDirection: $direction,
            debitRole: 'BANK',
            creditRole: 'INTEREST_REVENUE',
            pnlImpact: 'CREDIT_REVENUE'
        );
    }

    private function resolveBankFeeTax(string $direction, array $context): PostingMatrixDefinition
    {
        $subtype = $this->extractFeeTaxSubtype($context);

        if ($subtype === null) {
            throw new AmbiguousAccountMappingException(
                'BANK_FEE_TAX',
                'Cannot disambiguate BANK_FEE_TAX: missing explicit subtype (ADMIN_FEE or TAX) or distinguishable category code.'
            );
        }

        $debitRole = match ($subtype) {
            'ADMIN_FEE' => 'BANK_FEE_EXPENSE',
            'TAX' => 'TAX_EXPENSE',
            default => throw new AmbiguousAccountMappingException(
                'BANK_FEE_TAX',
                "Unrecognized subtype '{$subtype}' for BANK_FEE_TAX. Expected ADMIN_FEE or TAX."
            ),
        };

        return new PostingMatrixDefinition(
            category: PostingCategory::BANK_FEE_TAX,
            bankDirection: $direction,
            debitRole: $debitRole,
            creditRole: 'BANK',
            subtype: $subtype,
            pnlImpact: 'DEBIT_EXPENSE'
        );
    }

    private function resolveOperatingExpense(string $direction, array $context): PostingMatrixDefinition
    {
        return new PostingMatrixDefinition(
            category: PostingCategory::OPERATING_EXPENSE,
            bankDirection: $direction,
            debitRole: 'OPERATING_EXPENSE',
            creditRole: 'BANK',
            pnlImpact: 'DEBIT_EXPENSE'
        );
    }

    private function resolveAllocationDisbursement(string $direction, array $context): PostingMatrixDefinition
    {
        return new PostingMatrixDefinition(
            category: PostingCategory::ALLOCATION_DISBURSEMENT,
            bankDirection: $direction,
            debitRole: 'FUND_ALLOCATION',
            creditRole: 'BANK',
            pnlImpact: 'DEBIT_LIABILITY'
        );
    }

    private function resolveBankTransfer(string $direction, array $context): PostingMatrixDefinition
    {
        $transferContext = $context['transfer_context'] ?? null;

        if (! $transferContext instanceof BankTransferContext) {
            $sourceId = $context['source_bank_account_id'] ?? null;
            $destId = $context['destination_bank_account_id'] ?? null;

            if (empty($sourceId) || empty($destId)) {
                throw new InvalidBankTransferContextException(
                    'Bank transfer requires complete transfer context with both source_bank_account_id and destination_bank_account_id.'
                );
            }

            $transferContext = new BankTransferContext(
                sourceBankAccountId: (int) $sourceId,
                destinationBankAccountId: (int) $destId,
                sourceTransactionId: isset($context['source_transaction_id']) ? (int) $context['source_transaction_id'] : null,
                destinationTransactionId: isset($context['destination_transaction_id']) ? (int) $context['destination_transaction_id'] : null,
                amount: $context['amount'] ?? null
            );
        }

        // Transfer must have distinct source and destination bank accounts
        $transferContext->validate();

        $lineRoles = [
            ['role' => 'DESTINATION_BANK', 'side' => 'debit', 'description' => 'Penerimaan Transfer pada Rekening Tujuan'],
            ['role' => 'SOURCE_BANK', 'side' => 'credit', 'description' => 'Pengeluaran Transfer dari Rekening Sumber'],
        ];

        return new PostingMatrixDefinition(
            category: PostingCategory::BANK_TRANSFER,
            bankDirection: $direction,
            debitRole: 'DESTINATION_BANK',
            creditRole: 'SOURCE_BANK',
            isMultiLine: false,
            lineRoles: $lineRoles,
            transferContext: $transferContext,
            pnlImpact: 'ZERO'
        );
    }

    private function resolveUnappliedCash(string $direction, array $context): PostingMatrixDefinition
    {
        return new PostingMatrixDefinition(
            category: PostingCategory::UNAPPLIED_CASH,
            bankDirection: $direction,
            debitRole: 'BANK',
            creditRole: 'UNAPPLIED_CASH',
            pnlImpact: 'CREDIT_LIABILITY'
        );
    }

    private function extractFeeTaxSubtype(array $context): ?string
    {
        // 1. Direct explicit subtype
        if (! empty($context['subtype'])) {
            $raw = strtoupper(trim((string) $context['subtype']));
            if (in_array($raw, ['ADMIN_FEE', 'FEE', 'ADM', 'OUT-ADM-BANK'], true)) {
                return 'ADMIN_FEE';
            }
            if (in_array($raw, ['TAX', 'PAJAK', 'PAJAK_BUNGA', 'OUT-PAJAK-BUNGA'], true)) {
                return 'TAX';
            }
        }

        // 2. Category code
        if (! empty($context['category_code'])) {
            $code = strtoupper(trim((string) $context['category_code']));
            if ($code === 'OUT-ADM-BANK') {
                return 'ADMIN_FEE';
            }
            if ($code === 'OUT-PAJAK-BUNGA') {
                return 'TAX';
            }
        }

        return null;
    }
}
