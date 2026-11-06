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
