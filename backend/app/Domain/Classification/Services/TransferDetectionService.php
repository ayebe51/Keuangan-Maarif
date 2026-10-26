<?php

namespace App\Domain\Classification\Services;

use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\DTOs\ClassificationMatchResult;
use App\Domain\Classification\Enums\BusinessTransactionType;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Counterparty\Models\Counterparty;
use Illuminate\Support\Facades\DB;

class TransferDetectionService
{
    /**
     * Check if a transaction is an internal bank transfer between LP Ma'arif bank accounts.
     */
    public function detect(BankTransaction $transaction): ?ClassificationMatchResult
    {
        $orgId = (int) $transaction->organization_id;
        $desc = mb_strtolower((string) ($transaction->description ?? ''));

        $isInternalCounterparty = false;
        if ($transaction->counterparty_id) {
            $cp = Counterparty::withoutGlobalScopes()->find($transaction->counterparty_id);
            if ($cp && $cp->role === Counterparty::ROLE_INTERNAL) {
                $isInternalCounterparty = true;
            }
        }

        // Check transfer keywords
        $hasTransferKeyword = false;
        $keywords = ['trf', 'transfer', 'pindah buku', 'overboeking', 'pb ', 'pemindahbukuan'];
        foreach ($keywords as $kw) {
            if (mb_stripos($desc, $kw) !== false) {
                $hasTransferKeyword = true;
                break;
            }
        }

        // Must have transfer keyword or internal counterparty to be considered an internal transfer
        if (!$isInternalCounterparty && !$hasTransferKeyword) {
            return null;
        }

        // Check if other bank accounts of the same organization are mentioned in description
        $otherAccounts = BankAccount::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('id', '!=', $transaction->bank_account_id)
            ->get();

        $mentionsOtherAccount = false;
        $destinationBankId = null;
        foreach ($otherAccounts as $ba) {
            $cleanAcc = preg_replace('/[^0-9]/', '', $ba->account_number);
            $shortAcc = substr($cleanAcc, -3); // e.g. 308, 304, 538

            if (strlen($cleanAcc) >= 5 && mb_stripos($desc, $cleanAcc) !== false) {
                $mentionsOtherAccount = true;
                $destinationBankId = $ba->id;
                break;
            }

            if (strlen($shortAcc) === 3 && mb_stripos($desc, $shortAcc) !== false) {
                $mentionsOtherAccount = true;
                $destinationBankId = $ba->id;
                break;
            }
        }

        // Find potential paired opposite leg in another bank account
        $oppositeDirection = $transaction->isCashIn() ? 'OUT' : 'IN';
        $pairedBankTx = DB::table('bank_transactions')
            ->where('organization_id', $orgId)
            ->where('id', '!=', $transaction->id)
            ->where('bank_account_id', '!=', $transaction->bank_account_id)
            ->where('direction', $oppositeDirection)
            ->where('amount', $transaction->amount)
            ->whereBetween('transaction_date', [
                date('Y-m-d', strtotime($transaction->transaction_date . ' -3 days')),
                date('Y-m-d', strtotime($transaction->transaction_date . ' +3 days')),
            ])
            ->first();

        if (!$mentionsOtherAccount && !$pairedBankTx && !$isInternalCounterparty) {
            return null;
        }

        // Target cash/bank account from destination
        $targetAccountId = null;
        if ($destinationBankId) {
            $destAcc = BankAccount::withoutGlobalScopes()->find($destinationBankId);
            $targetAccountId = $destAcc ? $destAcc->account_id : null;
        } elseif ($pairedBankTx) {
            $destBa = BankAccount::withoutGlobalScopes()->find($pairedBankTx->bank_account_id);
            $targetAccountId = $destBa ? $destBa->account_id : null;
        }

        return new ClassificationMatchResult(
            isMatched: true,
            priority: 4, // Evaluated alongside deterministic rules
            method: ClassificationMethod::DETERMINISTIC_RULE,
            confidence: 0.95,
            transactionType: BusinessTransactionType::BANK_TRANSFER->value,
            targetAccountId: $targetAccountId,
            counterpartyId: $transaction->counterparty_id,
            autoPostEligible: true,
            evidence: "Internal transfer detected between LP Ma'arif bank accounts" . ($pairedBankTx ? " (Paired with Bank Tx #{$pairedBankTx->id})" : ""),
            explanation: "Internal transfer between LP Ma'arif accounts (P&L-neutral).",
            metadata: [
                'is_transfer' => true,
                'paired_bank_transaction_id' => $pairedBankTx ? $pairedBankTx->id : null,
                'destination_bank_account_id' => $destinationBankId,
            ]
        );
    }
}
