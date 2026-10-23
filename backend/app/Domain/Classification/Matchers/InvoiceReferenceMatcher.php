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
