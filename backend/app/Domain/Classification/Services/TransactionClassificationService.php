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

            // Run evaluation
            $match = $this->evaluate($transaction, $context);

            $year = (int) date('Y', strtotime($txDate));
            $txNumber = $existing ? $existing->transaction_number : BusinessTransaction::generateTransactionNumber($orgId, $year);

            // Determine status
            $classificationStatus = ClassificationStatus::UNCLASSIFIED;
            $autoPostEligible = false;

            if ($match->isMatched) {
                if ($match->priority <= 4 && $match->autoPostEligible) {
                    $classificationStatus = ClassificationStatus::CLASSIFIED;
                    $autoPostEligible = !$isPeriodLocked;
                } else {
                    $classificationStatus = ClassificationStatus::SUGGESTED;
                    $autoPostEligible = false;
                }
            }

            $txDir = is_object($transaction->direction) ? $transaction->direction->value : (string) $transaction->direction;
            $finalType = $match->transactionType ?? ($transaction->isCashIn() ? BusinessTransactionType::DIRECT_INCOME->value : BusinessTransactionType::OPERATING_EXPENSE->value);

            // Create or update BusinessTransaction
            $data = [
                'organization_id' => $orgId,
                'transaction_number' => $txNumber,
                'bank_transaction_id' => $transaction->id,
                'fiscal_period_id' => $fiscalPeriod ? $fiscalPeriod->id : 1,
                'transaction_date' => $txDate,
                'value_date' => $transaction->value_date,
                'transaction_type' => $finalType,
                'direction' => $txDir,
                'amount' => $transaction->amount,
                'counterparty_id' => $match->counterpartyId ?? $transaction->counterparty_id,
                'target_account_id' => $match->targetAccountId,
                'transaction_category_id' => $match->transactionCategoryId,
                'fund_id' => $match->fundId,
                'classification_status' => $classificationStatus,
                'classification_method' => $match->method->value,
                'classification_confidence' => $match->confidence,
                'is_period_locked' => $isPeriodLocked,
                'auto_post_eligible' => $autoPostEligible,
                'description' => $transaction->description ?? 'Bank Transaction',
                'notes' => $match->explanation,
                'status' => 'draft',
            ];

            if ($existing) {
                $existing->update($data);
                $bizTx = $existing->fresh();
            } else {
                $bizTx = BusinessTransaction::create($data);
            }

            // If Suggestion (P5 or P6), record in classification_suggestions
            if ($classificationStatus === ClassificationStatus::SUGGESTED) {
                ClassificationSuggestion::create([
                    'organization_id' => $orgId,
                    'bank_transaction_id' => $transaction->id,
                    'business_transaction_id' => $bizTx->id,
                    'suggested_type' => $match->transactionType ?? $finalType,
                    'suggested_account_id' => $match->targetAccountId,
                    'suggested_category_id' => $match->transactionCategoryId,
                    'suggested_counterparty_id' => $match->counterpartyId ?? $transaction->counterparty_id,
                    'confidence' => $match->confidence,
                    'evidence' => $match->evidence ?? '',
                    'explanation' => $match->explanation ?? '',
                    'metadata' => $match->metadata,
                    'status' => ClassificationSuggestion::STATUS_PENDING,
                ]);
            }

            // Sync bank_transactions
            $transaction->update([
                'classification_status' => $classificationStatus,
                'business_transaction_id' => $bizTx->id,
            ]);

            if ($user) {
                AuditService::log(
                    action: 'TRANSACTION_CLASSIFIED',
                    modelType: BusinessTransaction::class,
                    modelId: $bizTx->id,
                    newValues: [
                        'status' => $classificationStatus->value,
                        'method' => $match->method->value,
                        'priority' => $match->priority,
                        'confidence' => $match->confidence,
                    ],
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $bizTx;
        });
    }

    /**
     * Batch classify multiple bank transactions with preloaded context to prevent N+1 queries.
     */
    public function classifyBatch(array $bankTransactionIds, ?User $user = null): array
    {
        if (empty($bankTransactionIds)) {
            return [];
        }

        $transactions = BankTransaction::withoutGlobalScopes()
            ->whereIn('id', $bankTransactionIds)
            ->get();

        if ($transactions->isEmpty()) {
            return [];
        }

        $orgId = (int) $transactions->first()->organization_id;

        // Preload active classification rules ordered by priority
        $rules = ClassificationRule::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('priority', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->all();

        $context = ['rules' => $rules];

        $results = [];
        foreach ($transactions as $tx) {
            $results[] = $this->classifyTransaction($tx, $user, $context);
        }

        return $results;
    }

    /**
     * Apply an approved AI/heuristic suggestion.
     */
    public function applySuggestion(ClassificationSuggestion $suggestion, ?User $user = null): BusinessTransaction
    {
        return DB::transaction(function () use ($suggestion, $user) {
            $orgId = (int) $suggestion->organization_id;
            $bizTx = $suggestion->businessTransaction;

            if (!$bizTx) {
                throw new InvalidArgumentException("Associated business transaction not found.");
            }

            if ($bizTx->isPosted()) {
                throw new ImmutableClassifiedTransactionException("Cannot apply suggestion on posted transaction.");
            }

            // Update BusinessTransaction with suggested values
            $bizTx->update([
                'transaction_type' => $suggestion->suggested_type,
                'target_account_id' => $suggestion->suggested_account_id ?? $bizTx->target_account_id,
                'transaction_category_id' => $suggestion->suggested_category_id ?? $bizTx->transaction_category_id,
                'counterparty_id' => $suggestion->suggested_counterparty_id ?? $bizTx->counterparty_id,
                'classification_status' => ClassificationStatus::APPROVED,
                'auto_post_eligible' => !$bizTx->is_period_locked,
                'notes' => "Suggestion accepted: " . $suggestion->explanation,
            ]);

            // Mark suggestion accepted
            $suggestion->update([
                'status' => ClassificationSuggestion::STATUS_ACCEPTED,
                'reviewed_by' => $user?->id,
                'reviewed_at' => now(),
            ]);

            // Sync bank_transaction
            if ($bizTx->bankTransaction) {
                $bizTx->bankTransaction->update([
                    'classification_status' => ClassificationStatus::APPROVED,
                ]);
            }

            if ($user) {
                AuditService::log(
                    action: 'SUGGESTION_APPLIED',
                    modelType: ClassificationSuggestion::class,
                    modelId: $suggestion->id,
                    newValues: ['status' => 'accepted'],
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $bizTx->fresh();
        });
    }

    /**
     * Reject a suggestion.
     */
    public function rejectSuggestion(ClassificationSuggestion $suggestion, ?User $user = null): void
    {
        DB::transaction(function () use ($suggestion, $user) {
            $orgId = (int) $suggestion->organization_id;

            $suggestion->update([
                'status' => ClassificationSuggestion::STATUS_REJECTED,
                'reviewed_by' => $user?->id,
                'reviewed_at' => now(),
            ]);

            if ($user) {
                AuditService::log(
                    action: 'SUGGESTION_REJECTED',
                    modelType: ClassificationSuggestion::class,
                    modelId: $suggestion->id,
                    newValues: ['status' => 'rejected'],
                    userId: $user->id,
                    organizationId: $orgId
                );
            }
        });
    }

    /**
     * Manual operator override classification.
     */
    public function manualClassify(BankTransaction $transaction, array $data, ?User $user = null): BusinessTransaction
    {
        return DB::transaction(function () use ($transaction, $data, $user) {
            $orgId = (int) $transaction->organization_id;

            $existing = BusinessTransaction::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('bank_transaction_id', $transaction->id)
                ->first();

            if ($existing && $existing->isPosted()) {
                throw new ImmutableClassifiedTransactionException("Cannot manually reclassify a posted transaction.");
            }

            $txType = $data['transaction_type'];
            $bizType = BusinessTransactionType::tryFrom($txType);
            if (!$bizType) {
                throw new InvalidArgumentException("Invalid transaction type '{$txType}'.");
            }

            $txDir = is_object($transaction->direction) ? $transaction->direction->value : (string) $transaction->direction;
            if (!$bizType->isDirectionAllowed($txDir)) {
                throw new InvalidClassificationDirectionException(
                    "Direction '{$txDir}' is not allowed for transaction type '{$txType}'."
                );
            }

            $txDate = is_string($transaction->transaction_date)
                ? $transaction->transaction_date
                : $transaction->transaction_date->format('Y-m-d');

            $year = (int) date('Y', strtotime($txDate));
            $txNumber = $existing ? $existing->transaction_number : BusinessTransaction::generateTransactionNumber($orgId, $year);

            $fiscalPeriod = FiscalPeriod::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('start_date', '<=', $txDate)
                ->where('end_date', '>=', $txDate)
                ->first();

            $isPeriodLocked = $fiscalPeriod && $fiscalPeriod->isClosed();

            $bizData = [
                'organization_id' => $orgId,
                'transaction_number' => $txNumber,
                'bank_transaction_id' => $transaction->id,
                'fiscal_period_id' => $fiscalPeriod ? $fiscalPeriod->id : 1,
                'transaction_date' => $txDate,
                'value_date' => $transaction->value_date,
                'transaction_type' => $txType,
                'direction' => $txDir,
                'amount' => $transaction->amount,
                'counterparty_id' => $data['counterparty_id'] ?? $transaction->counterparty_id,
                'target_account_id' => $data['target_account_id'] ?? null,
                'transaction_category_id' => $data['transaction_category_id'] ?? null,
                'fund_id' => $data['fund_id'] ?? null,
                'classification_status' => ClassificationStatus::APPROVED,
                'classification_method' => ClassificationMethod::MANUAL->value,
                'classification_confidence' => 1.00,
                'is_period_locked' => $isPeriodLocked,
                'auto_post_eligible' => !$isPeriodLocked,
                'description' => $data['description'] ?? $transaction->description ?? 'Manual Classification',
                'notes' => $data['notes'] ?? 'Manually classified by operator',
                'status' => 'draft',
            ];

            if ($existing) {
                $existing->update($bizData);
                $bizTx = $existing->fresh();
            } else {
                $bizTx = BusinessTransaction::create($bizData);
            }

            // Sync bank_transaction
            $transaction->update([
                'classification_status' => ClassificationStatus::APPROVED,
                'business_transaction_id' => $bizTx->id,
            ]);

            // Save as rule if requested
            if (!empty($data['save_as_rule'])) {
                $pattern = $data['rule_pattern'] ?? $transaction->description;
                $this->ruleService->create([
                    'organization_id' => $orgId,
                    'name' => "Rule for " . substr($pattern, 0, 50),
                    'priority' => 100,
                    'rule_type' => 'KEYWORD',
                    'direction' => $txDir,
                    'pattern' => $pattern,
                    'pattern_type' => 'contains',
                    'transaction_type' => $txType,
                    'target_account_id' => $data['target_account_id'] ?? null,
                    'counterparty_id' => $data['counterparty_id'] ?? $transaction->counterparty_id,
                    'transaction_category_id' => $data['transaction_category_id'] ?? null,
                    'auto_post' => true,
                    'confidence' => 1.00,
                ], $user);
            }

            if ($user) {
                AuditService::log(
                    action: 'TRANSACTION_MANUALLY_CLASSIFIED',
                    modelType: BusinessTransaction::class,
                    modelId: $bizTx->id,
                    newValues: $bizData,
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $bizTx;
        });
    }
}
