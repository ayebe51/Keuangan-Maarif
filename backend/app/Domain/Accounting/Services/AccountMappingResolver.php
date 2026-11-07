<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DTOs\BankTransferContext;
use App\Domain\Accounting\DTOs\ResolvedPostingAccounts;
use App\Domain\Accounting\Enums\PostingCategory;
use App\Domain\Accounting\Exceptions\AmbiguousAccountMappingException;
use App\Domain\Accounting\Exceptions\InactiveAccountException;
use App\Domain\Accounting\Exceptions\InvalidAccountTypeException;
use App\Domain\Accounting\Exceptions\InvalidBankTransferContextException;
use App\Domain\Accounting\Exceptions\InvalidFundAccountMappingException;
use App\Domain\Accounting\Exceptions\InvalidNormalBalanceException;
use App\Domain\Accounting\Exceptions\NonPostableAccountException;
use App\Domain\Accounting\Exceptions\UnresolvedAccountMappingException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Classification\Models\TransactionCategory;
use App\Domain\Fund\Models\Fund;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;

class AccountMappingResolver
{
    public function __construct(
        protected PostingMatrixResolver $matrixResolver
    ) {}

    /**
     * Resolves COA accounts for a given posting category within an organization tenant context.
     * Pure domain resolver: Does not mutate database or ledger records.
     *
     * @param array{
     *     bank_account?: BankAccount|int|null,
     *     source_bank_account?: BankAccount|int|null,
     *     destination_bank_account?: BankAccount|int|null,
     *     fund?: Fund|int|null,
     *     target_account_id?: int|null,
     *     transaction_category_id?: int|null,
     *     category_code?: string|null,
     *     subtype?: string|null,
     *     direction?: string|null,
     *     is_overpayment?: bool,
     *     transfer_context?: BankTransferContext|null
     * } $context
     */
    public function resolve(PostingCategory $category, int $organizationId, array $context = []): ResolvedPostingAccounts
    {
        return match ($category) {
            PostingCategory::RECEIVABLE_PAYMENT => $this->resolveReceivablePaymentFlow($organizationId, $context),
            PostingCategory::DIRECT_INCOME => $this->resolveDirectIncomeFlow($organizationId, $context),
            PostingCategory::BANK_INTEREST => $this->resolveBankInterestFlow($organizationId, $context),
            PostingCategory::BANK_FEE_TAX => $this->resolveBankFeeTaxFlow($organizationId, $context),
            PostingCategory::OPERATING_EXPENSE => $this->resolveOperatingExpenseFlow($organizationId, $context),
            PostingCategory::ALLOCATION_DISBURSEMENT => $this->resolveAllocationDisbursementFlow($organizationId, $context),
            PostingCategory::BANK_TRANSFER => $this->resolveBankTransferFlow($organizationId, $context),
            PostingCategory::UNAPPLIED_CASH => $this->resolveUnappliedCashFlow($organizationId, $context),
        };
    }

    /**
     * Resolve the corresponding COA leaf asset account for a BankAccount entity.
     */
    public function resolveBankAccount(BankAccount|int $bankAccount, int $organizationId): Account
    {
        if (is_int($bankAccount)) {
            $model = BankAccount::withoutGlobalScopes()->find($bankAccount);
            if (! $model) {
                throw new UnresolvedAccountMappingException("BANK_ACCOUNT:{$bankAccount}", "BankAccount with ID {$bankAccount} not found.");
            }
            $bankAccount = $model;
        }

        // 1. Cross-tenant check
        if ((int) $bankAccount->organization_id !== $organizationId) {
            throw new CrossTenantViolationException(
                "BankAccount {$bankAccount->account_number} belongs to organization {$bankAccount->organization_id}, not {$organizationId}."
            );
        }

        // 2. Direct BankAccount.account_id
        $directAccount = null;
        if ($bankAccount->account_id) {
            $directAccount = Account::withoutGlobalScopes()->find($bankAccount->account_id);
        }

        // 3. Fallback to account_mappings (TYPE_BANK_ACCOUNT)
        $mappedAccount = null;
        $mappings = AccountMapping::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('mapping_type', AccountMapping::TYPE_BANK_ACCOUNT)
            ->where('mapping_key', (string) $bankAccount->id)
            ->where('is_active', true)
            ->get();

        if ($mappings->count() > 1) {
            throw new AmbiguousAccountMappingException(
                "BankAccount:{$bankAccount->account_number}",
                "Multiple active account mappings detected for bank account {$bankAccount->account_number}."
            );
        }

        if ($mappings->isNotEmpty()) {
            $mappedAccount = Account::withoutGlobalScopes()->find($mappings->first()->account_id);
        }

        // 4. Conflict detection: direct account_id vs account_mapping pointing to different accounts
        if ($directAccount !== null && $mappedAccount !== null && (int) $directAccount->id !== (int) $mappedAccount->id) {
            throw new AmbiguousAccountMappingException(
                "BankAccount:{$bankAccount->account_number}",
                "Conflicting account mappings for BankAccount '{$bankAccount->account_number}': direct account_id points to '{$directAccount->code}' while account_mapping points to '{$mappedAccount->code}'."
            );
        }

        $resolvedAccount = $directAccount ?? $mappedAccount;

        if ($resolvedAccount === null) {
            throw new UnresolvedAccountMappingException(
                "BankAccount:{$bankAccount->account_number}",
                "BankAccount '{$bankAccount->account_number}' has no linked account_id or active account_mapping."
            );
        }

        // 5. Universal account validation
        $this->validateAccount($resolvedAccount, $organizationId, Account::TYPE_ASSET, Account::BALANCE_DEBIT);

        return $resolvedAccount;
    }

    /**
     * Resolve the accounts receivable (AR) account.
     */
    public function resolveReceivableAccount(int $organizationId, array $context = []): Account
    {
        // 1. Explicit target_account_id
        if (! empty($context['target_account_id'])) {
            $account = Account::withoutGlobalScopes()->find($context['target_account_id']);
            if ($account) {
                $this->validateAccount($account, $organizationId, Account::TYPE_ASSET, Account::BALANCE_DEBIT);

                return $account;
            }
        }

        // 2. Explicit tenant-scoped mapping
        $mappingAccount = $this->resolveFromMapping($organizationId, AccountMapping::TYPE_TRANSACTION_TYPE, 'RECEIVABLE_PAYMENT');
        if ($mappingAccount) {
            $this->validateAccount($mappingAccount, $organizationId, Account::TYPE_ASSET, Account::BALANCE_DEBIT);

            return $mappingAccount;
        }

        // 3. Default canonical COA account for Piutang Madrasah (Code 1210)
        $canonical = Account::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', '1210')
            ->first();

        if ($canonical) {
            $this->validateAccount($canonical, $organizationId, Account::TYPE_ASSET, Account::BALANCE_DEBIT);

            return $canonical;
        }

        throw new UnresolvedAccountMappingException('RECEIVABLE', "No valid accounts receivable account found for organization {$organizationId}.");
    }

    /**
     * Resolve the operating revenue account.
     */
    public function resolveRevenueAccount(int $organizationId, array $context = []): Account
    {
        // 1. Explicit target_account_id
        if (! empty($context['target_account_id'])) {
            $account = Account::withoutGlobalScopes()->find($context['target_account_id']);
            if ($account) {
                $this->validateAccount($account, $organizationId, Account::TYPE_REVENUE, Account::BALANCE_CREDIT);

                return $account;
            }
        }

        // 2. TransactionCategory default credit account
        if (! empty($context['transaction_category_id'])) {
            $category = TransactionCategory::withoutGlobalScopes()->find($context['transaction_category_id']);
            if ($category && $category->default_credit_account_id) {
                $catAccount = Account::withoutGlobalScopes()->find($category->default_credit_account_id);
                if ($catAccount) {
                    $this->validateAccount($catAccount, $organizationId, Account::TYPE_REVENUE, Account::BALANCE_CREDIT);

                    return $catAccount;
                }
            }
        }

        // 3. Explicit tenant-scoped mapping
        $mappingAccount = $this->resolveFromMapping($organizationId, AccountMapping::TYPE_TRANSACTION_TYPE, 'DIRECT_INCOME');
        if ($mappingAccount) {
            $this->validateAccount($mappingAccount, $organizationId, Account::TYPE_REVENUE, Account::BALANCE_CREDIT);

            return $mappingAccount;
        }

        // 4. Default canonical revenue account (Code 4100)
        $canonical = Account::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', '4100')
            ->first();

        if ($canonical) {
            $this->validateAccount($canonical, $organizationId, Account::TYPE_REVENUE, Account::BALANCE_CREDIT);

            return $canonical;
        }

        throw new UnresolvedAccountMappingException('REVENUE', "No valid revenue account found for organization {$organizationId}.");
    }

    /**
     * Resolve bank interest / jasa giro revenue account.
     */
    public function resolveInterestRevenueAccount(int $organizationId, array $context = []): Account
    {
        // 1. Explicit target_account_id
        if (! empty($context['target_account_id'])) {
            $account = Account::withoutGlobalScopes()->find($context['target_account_id']);
            if ($account) {
                $this->validateAccount($account, $organizationId, Account::TYPE_REVENUE, Account::BALANCE_CREDIT);

                return $account;
            }
        }

        // 2. Explicit tenant-scoped mapping
        $mappingAccount = $this->resolveFromMapping($organizationId, AccountMapping::TYPE_TRANSACTION_TYPE, 'BANK_INTEREST');
        if ($mappingAccount) {
            $this->validateAccount($mappingAccount, $organizationId, Account::TYPE_REVENUE, Account::BALANCE_CREDIT);

            return $mappingAccount;
        }

        // 3. Category default credit account (e.g. IN-BUNGA-BANK)
        $cat = TransactionCategory::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', 'IN-BUNGA-BANK')
            ->first();
        if ($cat && $cat->default_credit_account_id) {
            $catAccount = Account::withoutGlobalScopes()->find($cat->default_credit_account_id);
            if ($catAccount) {
                $this->validateAccount($catAccount, $organizationId, Account::TYPE_REVENUE, Account::BALANCE_CREDIT);

                return $catAccount;
            }
        }

        // 4. Default canonical interest revenue account (Code 4200)
        $canonical = Account::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', '4200')
            ->first();

        if ($canonical) {
            $this->validateAccount($canonical, $organizationId, Account::TYPE_REVENUE, Account::BALANCE_CREDIT);

            return $canonical;
        }

        throw new UnresolvedAccountMappingException('INTEREST_REVENUE', "No valid bank interest revenue account found for organization {$organizationId}.");
    }

    /**
     * Resolve bank administrative fee expense account.
     */
    public function resolveBankFeeAccount(int $organizationId, array $context = []): Account
    {
        // 1. Explicit target_account_id
        if (! empty($context['target_account_id'])) {
            $account = Account::withoutGlobalScopes()->find($context['target_account_id']);
            if ($account) {
                $this->validateAccount($account, $organizationId, Account::TYPE_EXPENSE, Account::BALANCE_DEBIT);

                return $account;
            }
        }

        // 2. Explicit tenant-scoped mapping
        $mappingAccount = $this->resolveFromMapping($organizationId, AccountMapping::TYPE_TRANSACTION_TYPE, 'BANK_FEE');
        if ($mappingAccount) {
            $this->validateAccount($mappingAccount, $organizationId, Account::TYPE_EXPENSE, Account::BALANCE_DEBIT);

            return $mappingAccount;
        }

        // 3. Category default debit account (e.g. OUT-ADM-BANK)
        $cat = TransactionCategory::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', 'OUT-ADM-BANK')
            ->first();
        if ($cat && $cat->default_debit_account_id) {
            $catAccount = Account::withoutGlobalScopes()->find($cat->default_debit_account_id);
            if ($catAccount) {
                $this->validateAccount($catAccount, $organizationId, Account::TYPE_EXPENSE, Account::BALANCE_DEBIT);

                return $catAccount;
            }
        }

        // 4. Default canonical bank fee expense account (Code 5200)
        $canonical = Account::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', '5200')
            ->first();

        if ($canonical) {
            $this->validateAccount($canonical, $organizationId, Account::TYPE_EXPENSE, Account::BALANCE_DEBIT);

            return $canonical;
        }

        throw new UnresolvedAccountMappingException('BANK_FEE_EXPENSE', "No valid bank administrative fee account found for organization {$organizationId}.");
    }

    /**
     * Resolve bank interest tax expense account.
     */
    public function resolveBankTaxAccount(int $organizationId, array $context = []): Account
    {
        // 1. Explicit target_account_id
        if (! empty($context['target_account_id'])) {
            $account = Account::withoutGlobalScopes()->find($context['target_account_id']);
            if ($account) {
                $this->validateAccount($account, $organizationId, Account::TYPE_EXPENSE, Account::BALANCE_DEBIT);

                return $account;
            }
        }

        // 2. Explicit tenant-scoped mapping
        $mappingAccount = $this->resolveFromMapping($organizationId, AccountMapping::TYPE_TRANSACTION_TYPE, 'BANK_TAX');
        if ($mappingAccount) {
            $this->validateAccount($mappingAccount, $organizationId, Account::TYPE_EXPENSE, Account::BALANCE_DEBIT);

            return $mappingAccount;
        }

        // 3. Category default debit account (e.g. OUT-PAJAK-BUNGA)
        $cat = TransactionCategory::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', 'OUT-PAJAK-BUNGA')
            ->first();
        if ($cat && $cat->default_debit_account_id) {
            $catAccount = Account::withoutGlobalScopes()->find($cat->default_debit_account_id);
            if ($catAccount) {
                $this->validateAccount($catAccount, $organizationId, Account::TYPE_EXPENSE, Account::BALANCE_DEBIT);

                return $catAccount;
            }
        }

        // 4. Default canonical bank tax expense account (Code 5300)
        $canonical = Account::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', '5300')
            ->first();

        if ($canonical) {
            $this->validateAccount($canonical, $organizationId, Account::TYPE_EXPENSE, Account::BALANCE_DEBIT);

            return $canonical;
        }

        throw new UnresolvedAccountMappingException('TAX_EXPENSE', "No valid bank tax expense account found for organization {$organizationId}.");
    }

    /**
     * Resolve operating expense account.
     */
    public function resolveOperatingExpenseAccount(int $organizationId, array $context = []): Account
    {
        // 1. Explicit target_account_id
        if (! empty($context['target_account_id'])) {
            $account = Account::withoutGlobalScopes()->find($context['target_account_id']);
            if ($account) {
                $this->validateAccount($account, $organizationId, Account::TYPE_EXPENSE, Account::BALANCE_DEBIT);

                return $account;
            }
        }

        // 2. TransactionCategory default debit account
        if (! empty($context['transaction_category_id'])) {
            $category = TransactionCategory::withoutGlobalScopes()->find($context['transaction_category_id']);
            if ($category && $category->default_debit_account_id) {
                $catAccount = Account::withoutGlobalScopes()->find($category->default_debit_account_id);
                if ($catAccount) {
                    $this->validateAccount($catAccount, $organizationId, Account::TYPE_EXPENSE, Account::BALANCE_DEBIT);

                    return $catAccount;
                }
            }
        }

        // 3. Explicit tenant-scoped mapping
        $mappingAccount = $this->resolveFromMapping($organizationId, AccountMapping::TYPE_TRANSACTION_TYPE, 'OPERATING_EXPENSE');
        if ($mappingAccount) {
            $this->validateAccount($mappingAccount, $organizationId, Account::TYPE_EXPENSE, Account::BALANCE_DEBIT);

            return $mappingAccount;
        }

        // 4. Default canonical operating expense account (Code 5100)
        $canonical = Account::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', '5100')
            ->first();

        if ($canonical) {
            $this->validateAccount($canonical, $organizationId, Account::TYPE_EXPENSE, Account::BALANCE_DEBIT);

            return $canonical;
        }

        throw new UnresolvedAccountMappingException('OPERATING_EXPENSE', "No valid operating expense account found for organization {$organizationId}.");
    }

    /**
     * Resolve fund allocation liability account.
     */
    public function resolveFundAllocationAccount(Fund|int $fund, int $organizationId, array $context = []): Account
    {
        if (is_int($fund)) {
            $model = Fund::withoutGlobalScopes()->find($fund);
            if (! $model) {
                throw new InvalidFundAccountMappingException((string) $fund, "Fund with ID {$fund} not found.");
            }
            $fund = $model;
        }

        // 1. Cross-tenant check
        if ((int) $fund->organization_id !== $organizationId) {
            throw new CrossTenantViolationException(
                "Fund '{$fund->code}' belongs to organization {$fund->organization_id}, not {$organizationId}."
            );
        }

        // 2. Fund active check
        if (! $fund->is_active || $fund->status !== Fund::STATUS_ACTIVE) {
            throw new InvalidFundAccountMappingException($fund->code, "Fund '{$fund->code}' is inactive or closed.");
        }

        // 3. Direct Fund.account_id
        $resolvedAccount = null;
        if ($fund->account_id) {
            $resolvedAccount = Account::withoutGlobalScopes()->find($fund->account_id);
        }

        // 4. Fund-specific account_mapping
        if ($resolvedAccount === null) {
            $resolvedAccount = $this->resolveFromMapping($organizationId, 'fund', (string) $fund->id)
                ?? $this->resolveFromMapping($organizationId, 'fund', $fund->code);
        }

        // 5. Canonical fallback to special allocation liability account (e.g. 2110 or 2120)
        if ($resolvedAccount === null) {
            $resolvedAccount = Account::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('code', '2110')
                ->first();
        }

        if ($resolvedAccount === null) {
            throw new InvalidFundAccountMappingException(
                $fund->code,
                "No valid liability/restricted fund account mapping found for fund '{$fund->code}'."
            );
        }

        // 6. Universal account validation: Must be liability account (credit normal balance)
        $this->validateAccount($resolvedAccount, $organizationId, Account::TYPE_LIABILITY, Account::BALANCE_CREDIT);

        return $resolvedAccount;
    }

    /**
     * Resolve unapplied cash / temporary suspense liability account.
     */
    public function resolveUnappliedCashAccount(int $organizationId, array $context = []): Account
    {
        // 1. Explicit target_account_id
        if (! empty($context['target_account_id'])) {
            $account = Account::withoutGlobalScopes()->find($context['target_account_id']);
            if ($account) {
                $this->validateAccount($account, $organizationId, Account::TYPE_LIABILITY, Account::BALANCE_CREDIT);

                return $account;
            }
        }

        // 2. Explicit tenant-scoped mapping
        $mappingAccount = $this->resolveFromMapping($organizationId, AccountMapping::TYPE_TRANSACTION_TYPE, 'UNAPPLIED_CASH');
        if ($mappingAccount) {
            $this->validateAccount($mappingAccount, $organizationId, Account::TYPE_LIABILITY, Account::BALANCE_CREDIT);

            return $mappingAccount;
        }

        // 3. Default canonical unapplied cash account (Code 2200)
        $canonical = Account::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', '2200')
            ->first();

        if ($canonical) {
            $this->validateAccount($canonical, $organizationId, Account::TYPE_LIABILITY, Account::BALANCE_CREDIT);

            return $canonical;
        }

        throw new UnresolvedAccountMappingException('UNAPPLIED_CASH', "No valid unapplied cash account found for organization {$organizationId}.");
    }

    /**
     * Universal account verification enforcing strict accounting invariants.
     */
    public function validateAccount(
        Account $account,
        int $organizationId,
        ?string $expectedType = null,
        ?string $expectedNormalBalance = null
    ): void {
        // 1. Tenant boundary
        if ((int) $account->organization_id !== $organizationId) {
            throw new CrossTenantViolationException(
                "Account '{$account->code}' belongs to organization {$account->organization_id}, not current organization {$organizationId}."
            );
        }

        // 2. Active status
        if (! $account->is_active) {
            throw new InactiveAccountException($account->code, $account->name);
        }

        // 3. Postable flag
        if (! $account->is_postable) {
            throw new NonPostableAccountException($account->code, $account->name);
        }

        // 4. Leaf account verification: Must not have child accounts
        $hasChildren = Account::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('parent_id', $account->id)
            ->exists();

        if ($hasChildren) {
            throw new NonPostableAccountException(
                $account->code,
                $account->name,
                "Account '{$account->code} - {$account->name}' is a summary/header account with active child accounts and is not postable."
            );
        }

        // 5. Expected account type
        if ($expectedType !== null && strtolower($account->account_type) !== strtolower($expectedType)) {
            throw new InvalidAccountTypeException($account->code, $account->account_type, $expectedType);
        }

        // 6. Expected normal balance
        if ($expectedNormalBalance !== null && strtolower($account->normal_balance) !== strtolower($expectedNormalBalance)) {
            throw new InvalidNormalBalanceException($account->code, $account->normal_balance, $expectedNormalBalance);
        }
    }

    private function resolveReceivablePaymentFlow(int $organizationId, array $context): ResolvedPostingAccounts
    {
        $bankAccount = $this->requireBankAccountContext($context);
        $debitAccount = $this->resolveBankAccount($bankAccount, $organizationId);
        $creditAccount = $this->resolveReceivableAccount($organizationId, $context);

        $secondaryCreditAccount = null;
        if (! empty($context['is_overpayment'])) {
            $secondaryCreditAccount = $this->resolveUnappliedCashAccount($organizationId, $context);
        }

        return new ResolvedPostingAccounts(
            category: PostingCategory::RECEIVABLE_PAYMENT,
            debitAccount: $debitAccount,
            creditAccount: $creditAccount,
            bankAccount: $bankAccount,
            secondaryCreditAccount: $secondaryCreditAccount,
            metadata: ['is_overpayment' => ! empty($context['is_overpayment'])]
        );
    }

    private function resolveDirectIncomeFlow(int $organizationId, array $context): ResolvedPostingAccounts
    {
        $bankAccount = $this->requireBankAccountContext($context);
        $debitAccount = $this->resolveBankAccount($bankAccount, $organizationId);
        $creditAccount = $this->resolveRevenueAccount($organizationId, $context);

        return new ResolvedPostingAccounts(
            category: PostingCategory::DIRECT_INCOME,
            debitAccount: $debitAccount,
            creditAccount: $creditAccount,
            bankAccount: $bankAccount
        );
    }

    private function resolveBankInterestFlow(int $organizationId, array $context): ResolvedPostingAccounts
    {
        $bankAccount = $this->requireBankAccountContext($context);
        $debitAccount = $this->resolveBankAccount($bankAccount, $organizationId);
        $creditAccount = $this->resolveInterestRevenueAccount($organizationId, $context);

        return new ResolvedPostingAccounts(
            category: PostingCategory::BANK_INTEREST,
            debitAccount: $debitAccount,
            creditAccount: $creditAccount,
            bankAccount: $bankAccount
        );
    }

    private function resolveBankFeeTaxFlow(int $organizationId, array $context): ResolvedPostingAccounts
    {
        $bankAccount = $this->requireBankAccountContext($context);
        $creditAccount = $this->resolveBankAccount($bankAccount, $organizationId);

        // Disambiguate fee vs tax
        $matrixDef = $this->matrixResolver->resolve(PostingCategory::BANK_FEE_TAX, 'OUT', $context);
        $subtype = $matrixDef->getSubtype();

        $debitAccount = match ($subtype) {
            'ADMIN_FEE' => $this->resolveBankFeeAccount($organizationId, $context),
            'TAX' => $this->resolveBankTaxAccount($organizationId, $context),
            default => throw new AmbiguousAccountMappingException('BANK_FEE_TAX', "Cannot determine debit account for subtype '{$subtype}'."),
        };

        return new ResolvedPostingAccounts(
            category: PostingCategory::BANK_FEE_TAX,
            debitAccount: $debitAccount,
            creditAccount: $creditAccount,
            bankAccount: $bankAccount,
            metadata: ['subtype' => $subtype]
        );
    }

    private function resolveOperatingExpenseFlow(int $organizationId, array $context): ResolvedPostingAccounts
    {
        $bankAccount = $this->requireBankAccountContext($context);
        $debitAccount = $this->resolveOperatingExpenseAccount($organizationId, $context);
        $creditAccount = $this->resolveBankAccount($bankAccount, $organizationId);

        return new ResolvedPostingAccounts(
            category: PostingCategory::OPERATING_EXPENSE,
            debitAccount: $debitAccount,
            creditAccount: $creditAccount,
            bankAccount: $bankAccount
        );
    }

    private function resolveAllocationDisbursementFlow(int $organizationId, array $context): ResolvedPostingAccounts
    {
        $bankAccount = $this->requireBankAccountContext($context);
        $creditAccount = $this->resolveBankAccount($bankAccount, $organizationId);

        $fund = $context['fund'] ?? null;
        if (! $fund) {
            throw new InvalidFundAccountMappingException('UNKNOWN', 'Fund context is required for ALLOCATION_DISBURSEMENT.');
        }

        $debitAccount = $this->resolveFundAllocationAccount($fund, $organizationId, $context);

        return new ResolvedPostingAccounts(
            category: PostingCategory::ALLOCATION_DISBURSEMENT,
            debitAccount: $debitAccount,
            creditAccount: $creditAccount,
            bankAccount: $bankAccount,
            metadata: ['fund_id' => is_object($fund) ? $fund->id : $fund]
        );
    }

    private function resolveBankTransferFlow(int $organizationId, array $context): ResolvedPostingAccounts
    {
        $sourceBank = $context['source_bank_account'] ?? null;
        $destBank = $context['destination_bank_account'] ?? null;

        if (! $sourceBank || ! $destBank) {
            throw new InvalidBankTransferContextException(
                'Both source_bank_account and destination_bank_account are required to resolve a bank transfer.'
            );
        }

        $sourceBankAccount = is_int($sourceBank) ? BankAccount::withoutGlobalScopes()->find($sourceBank) : $sourceBank;
        $destBankAccount = is_int($destBank) ? BankAccount::withoutGlobalScopes()->find($destBank) : $destBank;

        if (! $sourceBankAccount || ! $destBankAccount) {
            throw new InvalidBankTransferContextException('Invalid bank account instances provided in transfer context.');
        }

        if ((int) $sourceBankAccount->id === (int) $destBankAccount->id) {
            throw new InvalidBankTransferContextException('Source and destination bank accounts cannot be identical.');
        }

        $creditAccount = $this->resolveBankAccount($sourceBankAccount, $organizationId);
        $debitAccount = $this->resolveBankAccount($destBankAccount, $organizationId);

        return new ResolvedPostingAccounts(
            category: PostingCategory::BANK_TRANSFER,
            debitAccount: $debitAccount,
            creditAccount: $creditAccount,
            sourceBankAccount: $sourceBankAccount,
            destinationBankAccount: $destBankAccount,
            metadata: ['pnl_impact' => '0.00']
        );
    }

    private function resolveUnappliedCashFlow(int $organizationId, array $context): ResolvedPostingAccounts
    {
        $bankAccount = $this->requireBankAccountContext($context);
        $debitAccount = $this->resolveBankAccount($bankAccount, $organizationId);
        $creditAccount = $this->resolveUnappliedCashAccount($organizationId, $context);

        return new ResolvedPostingAccounts(
            category: PostingCategory::UNAPPLIED_CASH,
            debitAccount: $debitAccount,
            creditAccount: $creditAccount,
            bankAccount: $bankAccount
        );
    }

    private function requireBankAccountContext(array $context): BankAccount
    {
        $bank = $context['bank_account'] ?? null;
        if (! $bank) {
            throw new UnresolvedAccountMappingException('BANK', 'A valid bank_account context is required.');
        }

        if (is_int($bank)) {
            $model = BankAccount::withoutGlobalScopes()->find($bank);
            if (! $model) {
                throw new UnresolvedAccountMappingException('BANK', "BankAccount with ID {$bank} does not exist.");
            }

            return $model;
        }

        return $bank;
    }

    private function resolveFromMapping(int $organizationId, string $mappingType, string $mappingKey): ?Account
    {
        $mappings = AccountMapping::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('mapping_type', $mappingType)
            ->where('mapping_key', $mappingKey)
            ->where('is_active', true)
            ->get();

        if ($mappings->count() > 1) {
            throw new AmbiguousAccountMappingException(
                "{$mappingType}:{$mappingKey}",
                "Multiple active mappings found for '{$mappingType}:{$mappingKey}' in organization {$organizationId}."
            );
        }

        if ($mappings->isNotEmpty()) {
            return Account::withoutGlobalScopes()->find($mappings->first()->account_id);
        }

        return null;
    }
}
