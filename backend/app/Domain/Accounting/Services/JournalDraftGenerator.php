<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DTOs\BankTransferContext;
use App\Domain\Accounting\DTOs\JournalDraftDTO;
use App\Domain\Accounting\DTOs\JournalLineDTO;
use App\Domain\Accounting\DTOs\PostingMatrixDefinition;
use App\Domain\Accounting\DTOs\ResolvedPostingAccounts;
use App\Domain\Accounting\Enums\PostingCategory;
use App\Domain\Accounting\Exceptions\AlreadyPostedException;
use App\Domain\Accounting\Exceptions\UnbalancedJournalException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntrySource;
use App\Domain\Accounting\ValueObjects\Money;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class JournalDraftGenerator
{
    public function __construct(
        protected JournalPostingService $journalPostingService,
        protected PostingMatrixResolver $matrixResolver,
        protected AccountMappingResolver $accountResolver,
        protected FiscalPeriodService $fiscalPeriodService
    ) {}

    /**
     * Atomically generates a balanced, validated double-entry JournalEntry in DRAFT status
     * for a given BusinessTransaction using the existing Phase 3 Accounting Core.
     *
     * @param array{
     *     category?: PostingCategory|null,
     *     subtype?: string|null,
     *     is_overpayment?: bool,
     *     receivable_amount?: string|Money|null,
     *     unapplied_amount?: string|Money|null,
     *     bank_account?: BankAccount|int|null,
     *     transfer_context?: BankTransferContext|null,
     *     source_bank_account?: BankAccount|int|null,
     *     destination_bank_account?: BankAccount|int|null,
     *     custom_lines?: array<int, JournalLineDTO>|null,
     *     leg_role?: string,
     *     additional_sources?: array<int, array{source_id: int, leg_role: string, business_transaction_id?: int}>
     * } $options
     */
    public function generate(BusinessTransaction $transaction, User $maker, array $options = []): JournalEntry
    {
        return DB::transaction(function () use ($transaction, $maker, $options) {
            // 1. Lock and validate source transaction
            $tx = BusinessTransaction::withoutGlobalScopes()
                ->where('id', $transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            $orgId = (int) $tx->organization_id;

            // Tenant boundary verification
            if (TenantContext::hasTenant() && (int) TenantContext::getTenantId() !== $orgId) {
                throw new CrossTenantViolationException(
                    "BusinessTransaction {$tx->transaction_number} belongs to organization {$orgId}, which does not match active tenant."
                );
            }

            // Status check
            if ($tx->isPosted()) {
                throw new AlreadyPostedException(
                    $tx->posted_journal_entry_id ? "JE-LINKED-{$tx->posted_journal_entry_id}" : $tx->transaction_number,
                    "BusinessTransaction {$tx->transaction_number} has already been posted and cannot generate a new draft."
                );
            }

            // 2. Idempotency Check: Return existing draft if one already exists
            $existingSource = JournalEntrySource::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('source_type', JournalEntrySource::TYPE_BUSINESS_TRANSACTION)
                ->where('source_id', $tx->id)
                ->first();

            if ($existingSource) {
                $existingDraft = JournalEntry::withoutGlobalScopes()->find($existingSource->journal_entry_id);
                if ($existingDraft && $existingDraft->isDraft()) {
                    return $existingDraft->fresh(['lines', 'sources']);
                }

                if ($existingDraft && $existingDraft->isPosted()) {
                    throw new AlreadyPostedException($existingDraft->entry_number);
                }
            }

            // 3. Resolve transaction date & fiscal period
            $entryDate = $tx->transaction_date instanceof Carbon
                ? $tx->transaction_date->format('Y-m-d')
                : (string) $tx->transaction_date;

            $fiscalPeriod = $this->fiscalPeriodService->ensureDateInOpenPeriod($entryDate, $orgId);

            // 4. Resolve PostingCategory
            $category = $options['category'] ?? PostingCategory::fromTransactionType($tx->transaction_type);

            // 5. Resolve Matrix Definition & Accounts
            $direction = (string) $tx->direction;
            $bankAccount = $options['bank_account']
                ?? $tx->bankTransaction?->bank_account_id
                ?? null;

            $matrixContext = array_merge([
                'category_code' => $tx->transactionCategory?->code,
                'subtype' => $options['subtype'] ?? null,
                'is_overpayment' => $options['is_overpayment'] ?? false,
                'source_bank_account_id' => is_object($options['source_bank_account'] ?? null)
                    ? $options['source_bank_account']->id
                    : ($options['source_bank_account'] ?? null),
                'destination_bank_account_id' => is_object($options['destination_bank_account'] ?? null)
                    ? $options['destination_bank_account']->id
                    : ($options['destination_bank_account'] ?? null),
                'transfer_context' => $options['transfer_context'] ?? null,
            ], $options);

            $matrixDef = $this->matrixResolver->resolve($category, $direction, $matrixContext);

            $accountContext = array_merge([
                'bank_account' => $bankAccount,
                'source_bank_account' => $options['source_bank_account'] ?? null,
                'destination_bank_account' => $options['destination_bank_account'] ?? null,
                'target_account_id' => $tx->target_account_id,
                'transaction_category_id' => $tx->transaction_category_id,
                'fund' => $tx->fund_id,
                'direction' => $direction,
                'is_overpayment' => $options['is_overpayment'] ?? false,
            ], $matrixContext);

            $resolvedAccounts = $this->accountResolver->resolve($category, $orgId, $accountContext);

            // 6. Construct Journal Lines
            $lines = $this->buildJournalLines($tx, $category, $matrixDef, $resolvedAccounts, $options);

            // 7. Determine Journal Entry Type
            $entryType = match ($category) {
                PostingCategory::BANK_TRANSFER => JournalEntry::TYPE_ADJUSTMENT,
                PostingCategory::RECEIVABLE_PAYMENT, PostingCategory::DIRECT_INCOME,
                PostingCategory::BANK_INTEREST, PostingCategory::UNAPPLIED_CASH => JournalEntry::TYPE_RECEIPT,
                PostingCategory::BANK_FEE_TAX, PostingCategory::OPERATING_EXPENSE,
                PostingCategory::ALLOCATION_DISBURSEMENT => JournalEntry::TYPE_PAYMENT,
            };

            // 8. Construct and Validate JournalDraftDTO
            $draftDto = new JournalDraftDTO(
                organizationId: $orgId,
                fiscalPeriodId: (int) $fiscalPeriod->id,
                entryDate: $entryDate,
                entryType: $entryType,
                description: $tx->description ?: "Draft jurnal untuk transaksi {$tx->transaction_number}",
                reference: $tx->transaction_number,
                sourceTransaction: $tx,
                postingCategory: $category,
                lines: $lines,
                createdBy: (int) $maker->id,
                legRole: $options['leg_role'] ?? JournalEntrySource::ROLE_PRIMARY
            );

            // 9. Call Core JournalPostingService::createDraft() — Sole Accounting Engine
            $draft = $this->journalPostingService->createDraft($draftDto->toDraftPayload(), $maker);

            // 10. Establish Canonical Source Relationship (journal_entry_sources)
            JournalEntrySource::create([
                'organization_id' => $orgId,
                'journal_entry_id' => $draft->id,
                'source_type' => JournalEntrySource::TYPE_BUSINESS_TRANSACTION,
                'source_id' => $tx->id,
                'business_transaction_id' => $tx->id,
                'leg_role' => $draftDto->legRole,
            ]);

            // Set primary compatibility pointer on journal_entries table
            $draft->business_transaction_id = $tx->id;
            $draft->save();

            // Handle any additional source links (e.g. transfer paired leg)
            if (! empty($options['additional_sources']) && is_array($options['additional_sources'])) {
                foreach ($options['additional_sources'] as $addSource) {
                    JournalEntrySource::create([
                        'organization_id' => $orgId,
                        'journal_entry_id' => $draft->id,
                        'source_type' => JournalEntrySource::TYPE_BUSINESS_TRANSACTION,
                        'source_id' => (int) $addSource['source_id'],
                        'business_transaction_id' => (int) ($addSource['business_transaction_id'] ?? $addSource['source_id']),
                        'leg_role' => $addSource['leg_role'] ?? JournalEntrySource::ROLE_SECONDARY,
                    ]);
                }
            }

            return $draft->fresh(['lines', 'sources']);
        });
    }

    /**
     * Builds dynamic JournalLineDTO collection based on matrix roles and amounts.
     *
     * @return array<int, JournalLineDTO>
     */
    private function buildJournalLines(
        BusinessTransaction $tx,
        PostingCategory $category,
        PostingMatrixDefinition $matrixDef,
        ResolvedPostingAccounts $resolvedAccounts,
        array $options
    ): array {
        // If custom validated lines are provided, return directly
        if (! empty($options['custom_lines']) && is_array($options['custom_lines'])) {
            return $options['custom_lines'];
        }

        $totalAmount = Money::of($tx->amount);
        $debitAcc = $resolvedAccounts->getDebitAccount();
        $creditAcc = $resolvedAccounts->getCreditAccount();

        // Multi-line Overpayment Flow (3 lines: Debit Bank, Credit Receivable, Credit Unapplied Cash)
        if ($matrixDef->isMultiLine() || ! empty($options['is_overpayment'])) {
            $receivableAmt = isset($options['receivable_amount'])
                ? Money::of($options['receivable_amount'])
                : $totalAmount;
            $unappliedAmt = isset($options['unapplied_amount'])
                ? Money::of($options['unapplied_amount'])
                : $totalAmount->subtract($receivableAmt);

            if (! $receivableAmt->add($unappliedAmt)->equals($totalAmount)) {
                throw new UnbalancedJournalException(
                    $totalAmount->getAmount(),
                    $receivableAmt->add($unappliedAmt)->getAmount(),
                    'Overpayment split amounts (receivable + unapplied cash) do not equal transaction total.'
                );
            }

            $unappliedAcc = $resolvedAccounts->secondaryCreditAccount
                ?? $this->accountResolver->resolveUnappliedCashAccount((int) $tx->organization_id);

            return [
                new JournalLineDTO(
                    accountId: (int) $debitAcc->id,
                    debit: $totalAmount,
                    credit: '0.00',
                    description: 'Penerimaan Kas/Bank dari Pelunasan Piutang',
                    bankAccountId: $resolvedAccounts->bankAccount?->id
                ),
                new JournalLineDTO(
                    accountId: (int) $creditAcc->id,
                    debit: '0.00',
                    credit: $receivableAmt,
                    description: 'Pelunasan Pokok Piutang Madrasah',
                    counterpartyId: $tx->counterparty_id
                ),
                new JournalLineDTO(
                    accountId: (int) $unappliedAcc->id,
                    debit: '0.00',
                    credit: $unappliedAmt,
                    description: 'Kelebihan Pembayaran (Titipan Kas / Unapplied Cash)',
                    counterpartyId: $tx->counterparty_id
                ),
            ];
        }

        // Standard 2-line journal
        $debitLine = new JournalLineDTO(
            accountId: (int) $debitAcc->id,
            debit: $totalAmount,
            credit: '0.00',
            description: $tx->description ?: "Debit {$debitAcc->name}",
            bankAccountId: $category === PostingCategory::BANK_TRANSFER
                ? $resolvedAccounts->destinationBankAccount?->id
                : ($debitAcc->account_type === Account::TYPE_ASSET ? $resolvedAccounts->bankAccount?->id : null),
            counterpartyId: $debitAcc->account_type === Account::TYPE_EXPENSE ? $tx->counterparty_id : null,
            fundId: $category === PostingCategory::ALLOCATION_DISBURSEMENT ? $tx->fund_id : null
        );

        $creditLine = new JournalLineDTO(
            accountId: (int) $creditAcc->id,
            debit: '0.00',
            credit: $totalAmount,
            description: $tx->description ?: "Kredit {$creditAcc->name}",
            bankAccountId: $category === PostingCategory::BANK_TRANSFER
                ? $resolvedAccounts->sourceBankAccount?->id
                : ($creditAcc->account_type === Account::TYPE_ASSET ? $resolvedAccounts->bankAccount?->id : null),
            counterpartyId: in_array($creditAcc->account_type, [Account::TYPE_REVENUE, Account::TYPE_ASSET], true) ? $tx->counterparty_id : null,
            fundId: null
        );

        return [$debitLine, $creditLine];
    }
}
