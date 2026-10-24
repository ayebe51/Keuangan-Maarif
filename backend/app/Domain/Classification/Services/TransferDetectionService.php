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
