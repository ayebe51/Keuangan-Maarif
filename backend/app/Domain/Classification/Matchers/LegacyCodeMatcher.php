<?php

namespace App\Domain\Classification\Matchers;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Contracts\LegacyCodeResolverInterface;
use App\Domain\Classification\DTOs\ClassificationMatchResult;
use App\Domain\Classification\Enums\BusinessTransactionType;
use App\Domain\Classification\Enums\ClassificationMethod;

class LegacyCodeMatcher implements LegacyCodeResolverInterface
{
    /**
     * Resolve legacy code from description or text.
     */
    public function resolve(int $organizationId, string $description): ?array
    {
        // Search for 4-digit code enclosed in brackets e.g. [1314] or standalone token e.g. "1314"
        if (!preg_match('/\b(1\d{3}|2\d{3}|3\d{3}|4\d{3}|5\d{3})\b/', $description, $matches)) {
            return null;
        }

        $code = $matches[1];

        $mapping = AccountMapping::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('mapping_type', AccountMapping::TYPE_LEGACY_CODE)
            ->where('mapping_key', $code)
            ->where('is_active', true)
            ->with('account')
            ->first();

        if (!$mapping || !$mapping->account) {
            return null;
        }

        $canonicalAccount = $mapping->account;
        $accCode = (string) $canonicalAccount->code;

        // Determine deduced transaction type
        $type = null;
        if (str_starts_with($accCode, '12') || str_starts_with($accCode, '13')) {
            $type = BusinessTransactionType::RECEIVABLE_PAYMENT->value;
        } elseif ($accCode === '4200' || $accCode === '4116') {
            $type = BusinessTransactionType::BANK_INTEREST->value;
        } elseif (str_starts_with($accCode, '4')) {
            $type = BusinessTransactionType::DIRECT_INCOME->value;
        } elseif ($accCode === '5200' || $accCode === '5116') {
            $type = BusinessTransactionType::BANK_FEE_TAX->value;
        } elseif (str_starts_with($accCode, '5')) {
            $type = BusinessTransactionType::OPERATING_EXPENSE->value;
        } elseif (str_starts_with($accCode, '21') || str_starts_with($accCode, '22')) {
            $type = BusinessTransactionType::ALLOCATION_DISBURSEMENT->value;
        }

        return [
            'legacy_code' => $code,
            'account_id' => (int) $canonicalAccount->id,
            'canonical_code' => $accCode,
            'transaction_type' => $type,
        ];
    }

    /**
     * Match bank transaction against legacy code mapping (Priority 3).
     */
    public function match(BankTransaction $transaction): ?ClassificationMatchResult
    {
        $orgId = (int) $transaction->organization_id;
        $desc = (string) ($transaction->description ?? '');
        $resolved = $this->resolve($orgId, $desc);

        if (!$resolved) {
            return null;
        }

        $type = $resolved['transaction_type'] ?? null;
        if (!$type) {
            return null;
        }

        // Validate direction compatibility
        $bizType = BusinessTransactionType::tryFrom($type);
        $txDir = is_object($transaction->direction) ? $transaction->direction->value : (string) $transaction->direction;
        if ($bizType && !$bizType->isDirectionAllowed($txDir)) {
            return null; // Direction mismatch, reject legacy mapping match
        }

        return new ClassificationMatchResult(
            isMatched: true,
            priority: 3,
            method: ClassificationMethod::LEGACY_CODE,
            confidence: 1.00,
            transactionType: $type,
            targetAccountId: $resolved['account_id'],
            counterpartyId: $transaction->counterparty_id,
            autoPostEligible: true,
            evidence: "Extracted legacy code '{$resolved['legacy_code']}' -> mapped to canonical account {$resolved['canonical_code']}",
            explanation: "Legacy code mapping successfully resolved to account {$resolved['canonical_code']}",
            metadata: $resolved
        );
    }
}
