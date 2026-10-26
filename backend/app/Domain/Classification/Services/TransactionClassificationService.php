<?php

namespace App\Domain\Classification\Services;

use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Contracts\ClassificationRuleEngineInterface;
use App\Domain\Classification\DTOs\ClassificationMatchResult;
use App\Domain\Classification\Enums\BusinessTransactionType;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Exceptions\ImmutableClassifiedTransactionException;
use App\Domain\Classification\Exceptions\InvalidClassificationDirectionException;
use App\Domain\Classification\Matchers\DeterministicRuleMatcher;
use App\Domain\Classification\Matchers\ExplicitMappingMatcher;
use App\Domain\Classification\Matchers\HistoricalHeuristicMatcher;
use App\Domain\Classification\Matchers\InvoiceReferenceMatcher;
use App\Domain\Classification\Matchers\LegacyCodeMatcher;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Models\ClassificationSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TransactionClassificationService implements ClassificationRuleEngineInterface
{
    public function __construct(
        protected ExplicitMappingMatcher $explicitMatcher,
        protected InvoiceReferenceMatcher $invoiceMatcher,
        protected LegacyCodeMatcher $legacyMatcher,
        protected DeterministicRuleMatcher $deterministicMatcher,
        protected HistoricalHeuristicMatcher $historicalMatcher,
        protected AiSuggestionService $aiService,
        protected TransferDetectionService $transferService,
        protected ClassificationRuleService $ruleService
    ) {}

    /**
     * Evaluate a bank transaction through the 7-tier precedence pipeline.
     */
    public function evaluate(BankTransaction $transaction, array $context = []): ClassificationMatchResult
    {
        $rules = $context['rules'] ?? [];

        // 0. Transfer Detection (Internal transfer between LP bank accounts)
        $transferMatch = $this->transferService->detect($transaction);
        if ($transferMatch) {
            return $transferMatch;
        }

        // Priority 1: Explicit Approved User Mapping
        $p1 = $this->explicitMatcher->match($transaction, $rules);
        if ($p1) {
            return $p1;
        }

        // Priority 2: Exact Invoice / Reference Match
        $p2 = $this->invoiceMatcher->match($transaction);
        if ($p2) {
            return $p2;
        }

        // Priority 3: Approved Legacy Code Mapping
        $p3 = $this->legacyMatcher->match($transaction);
        if ($p3) {
            return $p3;
        }

        // Priority 4: Deterministic Category / Text Rules
        $p4 = $this->deterministicMatcher->match($transaction, $rules);
        if ($p4) {
            return $p4;
        }

        // Priority 5: Counterparty + Historical Pattern Heuristic (SUGGESTION ONLY)
        $p5 = $this->historicalMatcher->match($transaction);
        if ($p5) {
            return $p5;
        }

        // Priority 6: AI / Fuzzy Suggestion Engine (SUGGESTION ONLY - NEVER AUTO-POST)
        $p6 = $this->aiService->match($transaction, $context);
        if ($p6) {
            return $p6;
        }

        // Priority 7: Unclassified Fallback
        return ClassificationMatchResult::unclassified();
    }

    /**
     * Classify a single BankTransaction and persist the resulting BusinessTransaction.
     */
    public function classifyTransaction(BankTransaction $transaction, ?User $user = null, array $context = []): BusinessTransaction
    {
        return DB::transaction(function () use ($transaction, $user, $context) {
            $orgId = (int) $transaction->organization_id;

            // Check if existing business transaction is already posted
            $existing = BusinessTransaction::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('bank_transaction_id', $transaction->id)
                ->first();

            if ($existing && $existing->isPosted()) {
                throw new ImmutableClassifiedTransactionException(
                    "Cannot reclassify transaction #{$existing->transaction_number} because it has already been posted to ledger."
                );
            }

            // Resolve fiscal period
            $txDate = is_string($transaction->transaction_date)
                ? $transaction->transaction_date
                : $transaction->transaction_date->format('Y-m-d');

            $fiscalPeriod = FiscalPeriod::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('start_date', '<=', $txDate)
                ->where('end_date', '>=', $txDate)
                ->first();

            if (!$fiscalPeriod) {
                // Fallback to active/open period
                $fiscalPeriod = FiscalPeriod::withoutGlobalScopes()
                    ->where('organization_id', $orgId)
                    ->where('status', FiscalPeriod::STATUS_OPEN)
                    ->first();
            }

            $isPeriodLocked = $fiscalPeriod && $fiscalPeriod->isClosed();

