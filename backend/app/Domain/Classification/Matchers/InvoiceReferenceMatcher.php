<?php

namespace App\Domain\Classification\Matchers;

use App\Domain\Accounting\Models\Account;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\DTOs\ClassificationMatchResult;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Enums\BusinessTransactionType;
use Illuminate\Support\Facades\DB;

class InvoiceReferenceMatcher
{
    /**
     * Match incoming payment against outstanding receivables (Priority 2).
     */
    public function match(BankTransaction $transaction): ?ClassificationMatchResult
    {
        // Invoice matching only applies to incoming cash (Bank Credit = IN)
        if (!$transaction->isCashIn()) {
            return null;
        }

        $orgId = (int) $transaction->organization_id;
        $desc = (string) ($transaction->description ?? '');
        $ref = (string) ($transaction->reference_number ?? '');
        $txAmount = (float) $transaction->amount;

        // 1. Try to extract reference matching format RCV-... or INV-...
        $matchedNumber = null;
        if (preg_match('/(RCV-[A-Za-z0-9\-]+|INV-[A-Za-z0-9\-]+)/i', $desc . ' ' . $ref, $matches)) {
            $matchedNumber = strtoupper($matches[1]);
        }

        $receivable = null;
        if ($matchedNumber) {
            $receivable = DB::table('receivables')
                ->where('organization_id', $orgId)
                ->whereIn('status', ['outstanding', 'partial'])
                ->where(function ($q) use ($matchedNumber) {
                    $q->whereRaw('UPPER(receivable_number) = ?', [$matchedNumber])
                      ->orWhereRaw('UPPER(description) LIKE ?', ["%{$matchedNumber}%"]);
                })
                ->first();
        }

        // 2. Fallback: if counterparty is resolved and exact amount matches single outstanding receivable
        if (!$receivable && $transaction->counterparty_id) {
            $matchingRecs = DB::table('receivables')
                ->where('organization_id', $orgId)
                ->where('counterparty_id', $transaction->counterparty_id)
                ->whereIn('status', ['outstanding', 'partial'])
                ->where('outstanding_amount', '>', 0)
                ->get();

            // Check exact amount match
            foreach ($matchingRecs as $rec) {
                if (abs((float) $rec->outstanding_amount - $txAmount) < 0.01) {
                    $receivable = $rec;
                    break;
                }
            }
        }

        if (!$receivable) {
            return null;
        }

        $outstanding = (float) $receivable->outstanding_amount;
        $isOverpaid = $txAmount > $outstanding;
        $excessAmount = $isOverpaid ? round($txAmount - $outstanding, 2) : 0.00;

        // Resolve default AR account (1210 or 1311/1314)
        $arAccount = DB::table('accounts')
            ->where('organization_id', $orgId)
            ->where(function ($q) {
                $q->where('code', 'LIKE', '12%')
                  ->orWhere('code', 'LIKE', '13%');
            })
            ->where('is_postable', true)
            ->orderBy('code')
            ->first();

        $accountId = $arAccount ? $arAccount->id : null;

        return new ClassificationMatchResult(
            isMatched: true,
            priority: 2,
            method: ClassificationMethod::INVOICE_REFERENCE,
            confidence: 1.00,
            transactionType: BusinessTransactionType::RECEIVABLE_PAYMENT->value,
            targetAccountId: $accountId,
            counterpartyId: (int) $receivable->counterparty_id,
            autoPostEligible: !$isOverpaid, // Overpayment requires review to allocate unapplied cash
            evidence: "Matched Receivable {$receivable->receivable_number} (Outstanding: Rp " . number_format($outstanding, 2) . ")",
            explanation: $isOverpaid
                ? "Payment of Rp " . number_format($txAmount, 2) . " exceeds outstanding balance of Rp " . number_format($outstanding, 2) . ". Excess of Rp " . number_format($excessAmount, 2) . " routed to UNAPPLIED_CASH."
                : "Exact match for receivable {$receivable->receivable_number}",
            metadata: [
                'receivable_id' => $receivable->id,
                'receivable_number' => $receivable->receivable_number,
                'outstanding_amount' => $outstanding,
                'is_overpaid' => $isOverpaid,
                'excess_amount' => $excessAmount,
            ]
        );
    }
}
