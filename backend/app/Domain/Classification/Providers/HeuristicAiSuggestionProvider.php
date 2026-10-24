<?php

namespace App\Domain\Classification\Providers;

use App\Domain\Accounting\Models\Account;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Contracts\AiSuggestionInterface;
use App\Domain\Classification\DTOs\AiSuggestionResult;
use App\Domain\Classification\Enums\BusinessTransactionType;
use Illuminate\Support\Facades\DB;

class HeuristicAiSuggestionProvider implements AiSuggestionInterface
{
    /**
     * Provide offline / test deterministic AI suggestion with reasoning and evidence.
     * Note: AI Suggestions NEVER auto-post.
     */
    public function suggest(BankTransaction $transaction, array $context = []): ?AiSuggestionResult
    {
        $orgId = (int) $transaction->organization_id;
        $desc = mb_strtolower((string) ($transaction->description ?? ''));
        $isCashIn = $transaction->isCashIn();

        if ($desc === '') {
            return null;
        }

        $type = null;
        $accountPrefix = null;
        $evidence = '';
        $explanation = '';

        if ($isCashIn) {
            if ($this->containsAny($desc, ['bunga', 'jasa giro', 'interest'])) {
                $type = BusinessTransactionType::BANK_INTEREST->value;
                $accountPrefix = '42'; // 4200 Pendapatan Jasa Giro & Bunga Bank
                $evidence = "Detected banking interest indicators in incoming credit";
                $explanation = "Transaction description contains interest/giro service tokens.";
            } elseif ($this->containsAny($desc, ['bantuan', 'hibah', 'subsidi', 'donasi'])) {
                $type = BusinessTransactionType::DIRECT_INCOME->value;
                $accountPrefix = '41';
                $evidence = "Detected grant/subsidy tokens ('bantuan' / 'hibah')";
                $explanation = "Classified as direct grant income based on NLP pattern matching.";
            } elseif ($this->containsAny($desc, ['infaq', 'iuran', 'spp', 'setoran', 'darwata', 'madrasah', 'sekolah', 'siswa'])) {
                $type = BusinessTransactionType::DIRECT_INCOME->value;
                $accountPrefix = '41';
                $evidence = "Detected educational fee / contribution keywords";
                $explanation = "Incoming transfer identified as institutional contribution or school receipt.";
            } else {
                // If no keywords match, do NOT suggest anything blindly
                return null;
            }
        } else {
            if ($this->containsAny($desc, ['adm', 'admin', 'pajak', 'biaya bank', 'materai'])) {
                $type = BusinessTransactionType::BANK_FEE_TAX->value;
                $accountPrefix = '52'; // 5200 Beban Administrasi Bank
                $evidence = "Detected bank charge or tax deduction tokens ('adm' / 'pajak')";
                $explanation = "Identified as bank administrative charge or transaction fee.";
            } elseif ($this->containsAny($desc, ['beasiswa', 'alokasi', 'penyaluran', 'santunan', 'baksos', 'dana cadangan'])) {
                $type = BusinessTransactionType::ALLOCATION_DISBURSEMENT->value;
                $accountPrefix = '21';
                $evidence = "Detected allocation / disbursement tokens ('beasiswa' / 'penyaluran')";
                $explanation = "Classified as fund allocation disbursement.";
            } elseif ($this->containsAny($desc, ['listrik', 'pln', 'air', 'pdam', 'internet', 'wifi', 'atk', 'konsumsi', 'snack', 'fotokopi', 'honor', 'gaji', 'rapat'])) {
                $type = BusinessTransactionType::OPERATING_EXPENSE->value;
                $accountPrefix = '51'; // 5100 Beban Operasional Kantor
                $evidence = "Detected operational expense indicators ('{$desc}')";
                $explanation = "Classified as routine operational office expense.";
            } else {
                // If no keywords match, do NOT suggest anything blindly
                return null;
            }
        }

        // Attempt to find candidate account
        $targetAccount = null;
        if ($accountPrefix) {
            $targetAccount = DB::table('accounts')
                ->where('organization_id', $orgId)
                ->where('code', 'LIKE', "{$accountPrefix}%")
                ->where('is_postable', true)
                ->orderBy('code')
                ->first();
        }

        return new AiSuggestionResult(
            confidence: 0.85,
            suggestedType: $type,
            suggestedAccountId: $targetAccount ? (int) $targetAccount->id : null,
            suggestedCategoryId: null,
            suggestedCounterpartyId: $transaction->counterparty_id,
            evidence: $evidence,
            explanation: $explanation,
            metadata: [
                'provider' => 'heuristic_v1',
                'description_analyzed' => $desc,
                'target_prefix' => $accountPrefix,
            ]
        );
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (mb_stripos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
