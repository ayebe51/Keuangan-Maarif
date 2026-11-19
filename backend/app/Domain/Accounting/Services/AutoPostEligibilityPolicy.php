<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DTOs\AutoPostEvaluationResult;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntrySource;
use App\Domain\Accounting\ValueObjects\Money;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Classification\Models\ClassificationRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AutoPostEligibilityPolicy
{
    /**
     * Deterministically evaluates whether a BusinessTransaction is eligible for automated posting
     * against all 11 gates, security invariants, and domain boundaries.
     */
    public function evaluate(BusinessTransaction $transaction): AutoPostEvaluationResult
    {
        $orgId = (int) $transaction->organization_id;

        // 1. Transaction Posting Lifecycle Guard
        if ($transaction->isPosted() || $transaction->posted_journal_entry_id !== null) {
            return AutoPostEvaluationResult::alreadyPosted(
                $transaction->id,
                $transaction->posted_journal_entry_id
            );
        }

        if ($transaction->isRejected() || $transaction->status === 'rejected') {
            return AutoPostEvaluationResult::blocked(
                $transaction->id,
                'TRANSACTION_REJECTED',
                "BusinessTransaction {$transaction->transaction_number} is rejected and cannot be auto-posted."
            );
        }

        // 2. Classification State Guard
        $isClassified = $transaction->classification_status === ClassificationStatus::CLASSIFIED
            || (is_string($transaction->classification_status) && strtolower($transaction->classification_status) === 'classified');

        if (! $isClassified) {
            return AutoPostEvaluationResult::requiresHumanReview(
                $transaction->id,
                'NOT_CLASSIFIED',
                "Transaction status is not CLASSIFIED (current: {$transaction->classification_status?->value})."
            );
        }

        if (! $transaction->auto_post_eligible) {
            return AutoPostEvaluationResult::requiresHumanReview(
                $transaction->id,
                'AUTO_POST_NOT_ELIGIBLE',
                "Transaction auto_post_eligible flag is false for {$transaction->transaction_number}."
            );
        }

        // 3. Domain Boundary Guards
        if ($transaction->transaction_type === 'RECEIVABLE_PAYMENT') {
            return AutoPostEvaluationResult::blocked(
                $transaction->id,
                'RECEIVABLE_PAYMENT_BLOCKED',
                'Automated posting for RECEIVABLE_PAYMENT is strictly blocked pending Phase 8 subledger.'
            );
        }

        if ($transaction->transaction_type === 'UNAPPLIED_CASH') {
            return AutoPostEvaluationResult::requiresHumanReview(
                $transaction->id,
                'UNAPPLIED_CASH_BLOCKED',
                'Automated posting for UNAPPLIED_CASH is prohibited. Requires Human Review.'
            );
        }

        if ($transaction->transaction_type === 'BANK_TRANSFER') {
            if ($transaction->transfer_pairing_status === 'PAIRING_AMBIGUOUS') {
                return AutoPostEvaluationResult::requiresHumanReview(
                    $transaction->id,
                    'TRANSFER_PAIRING_AMBIGUOUS',
                    'Bank transfer has ambiguous twin pairings. Routed to Transfer Review Queue.'
                );
            }
            if ($transaction->transfer_pairing_status !== 'PAIRED') {
                return AutoPostEvaluationResult::requiresHumanReview(
                    $transaction->id,
                    'TRANSFER_UNPAIRED',
                    "Bank transfer pairing status is '{$transaction->transfer_pairing_status}' (must be PAIRED)."
                );
            }
        }

        // 4. Positive Amount Guard
        $txAmount = Money::of($transaction->amount);
        if (! $txAmount->isPositive()) {
            return AutoPostEvaluationResult::blocked(
                $transaction->id,
                'NON_POSITIVE_AMOUNT',
                'Transaction amount must be strictly positive.'
            );
        }

        // 5. Rule Identification & Governance
        $ruleId = $transaction->rule_snapshot['rule_id'] ?? null;
        if (! $ruleId) {
            // Check target category rule if available
            return AutoPostEvaluationResult::requiresHumanReview(
                $transaction->id,
                'RULE_NOT_IDENTIFIED',
                'No classification rule snapshot or identifier associated with this transaction.'
            );
        }

        $rule = ClassificationRule::withoutGlobalScopes()
            ->where('id', $ruleId)
            ->first();

        if (! $rule) {
            return AutoPostEvaluationResult::permanentFailure(
                $transaction->id,
                'RULE_NOT_FOUND',
                "ClassificationRule #{$ruleId} does not exist in the database.",
                $ruleId
            );
        }

        if ((int) $rule->organization_id !== $orgId) {
            return AutoPostEvaluationResult::permanentFailure(
                $transaction->id,
                'TENANT_MISMATCH',
                "ClassificationRule #{$ruleId} belongs to organization {$rule->organization_id}, expected {$orgId}.",
                $ruleId
            );
        }

        if (! $rule->is_active) {
            return AutoPostEvaluationResult::blocked(
                $transaction->id,
                'RULE_INACTIVE',
                "ClassificationRule #{$ruleId} is inactive / revoked.",
                $ruleId
            );
        }

        if (! $rule->approved_by || ! $rule->approved_at) {
            return AutoPostEvaluationResult::blocked(
                $transaction->id,
                'RULE_NOT_APPROVED',
                "ClassificationRule #{$ruleId} has not been approved.",
                $ruleId
            );
        }

        $approver = User::withoutGlobalScopes()->find($rule->approved_by);
        if (! $approver || ! $approver->is_active) {
            return AutoPostEvaluationResult::blocked(
                $transaction->id,
                'RULE_APPROVER_INACTIVE',
                "Rule Approver #{$rule->approved_by} is inactive or does not exist.",
                $ruleId
            );
        }

        $hasApprovePermission = $approver->isSuperAdmin()
            || (method_exists($approver, 'hasPermissionTo') && ($approver->hasPermissionTo('journal.approve', 'sanctum') || $approver->hasPermissionTo('journal.approve')))
            || (method_exists($approver, 'can') && $approver->can('journal.approve'));

        if (! $hasApprovePermission) {
            return AutoPostEvaluationResult::blocked(
                $transaction->id,
                'UNAUTHORIZED_RULE_APPROVER',
                "Rule Approver #{$approver->id} does not hold required 'journal.approve' permission.",
                $ruleId
            );
        }

        if (! $rule->auto_post) {
            return AutoPostEvaluationResult::blocked(
                $transaction->id,
                'AUTO_POST_DISABLED',
                "ClassificationRule #{$ruleId} has auto_post set to false.",
                $ruleId
            );
        }

        $maxAutoPostAmount = Money::of($rule->max_auto_post_amount ?? '0.00');
        if (! $maxAutoPostAmount->isPositive()) {
            return AutoPostEvaluationResult::blocked(
                $transaction->id,
                'ZERO_AUTO_POST_LIMIT',
                "ClassificationRule #{$ruleId} has max_auto_post_amount of 0.00 (disabled by default).",
                $ruleId
            );
        }

        if ($txAmount->isGreaterThan($maxAutoPostAmount)) {
            return AutoPostEvaluationResult::requiresHumanReview(
                $transaction->id,
                'AMOUNT_EXCEEDS_THRESHOLD',
                "Transaction amount ({$txAmount->getAmount()}) exceeds rule max auto-post threshold ({$maxAutoPostAmount->getAmount()}).",
                $ruleId
            );
        }

        // 6. Effective Date Range Check
        $txDate = $transaction->transaction_date instanceof Carbon
            ? $transaction->transaction_date->format('Y-m-d')
            : (string) $transaction->transaction_date;

        if ($rule->effective_from) {
            $effFrom = $rule->effective_from instanceof Carbon
                ? $rule->effective_from->format('Y-m-d')
                : (string) $rule->effective_from;
            if ($txDate < $effFrom) {
                return AutoPostEvaluationResult::blocked(
                    $transaction->id,
                    'TRANSACTION_BEFORE_RULE_EFFECTIVE',
                    "Transaction date '{$txDate}' is before rule effective_from '{$effFrom}'.",
                    $ruleId
                );
            }
        }

        if ($rule->effective_to) {
            $effTo = $rule->effective_to instanceof Carbon
                ? $rule->effective_to->format('Y-m-d')
                : (string) $rule->effective_to;
            if ($txDate > $effTo) {
                return AutoPostEvaluationResult::blocked(
                    $transaction->id,
                    'RULE_EXPIRED',
                    "Transaction date '{$txDate}' is after rule effective_to '{$effTo}'.",
                    $ruleId
                );
            }
        }

        // 7. Rule Version Integrity Check
        if (isset($transaction->rule_snapshot['version']) && (int) $rule->version !== (int) $transaction->rule_snapshot['version']) {
            return AutoPostEvaluationResult::blocked(
                $transaction->id,
                'RULE_VERSION_MISMATCH',
                "Rule version mismatch for Rule #{$ruleId}: expected {$transaction->rule_snapshot['version']}, database has {$rule->version}.",
                $ruleId
            );
        }

        // 8. Segregation of Duties (Maker-Checker Governance)
        // 8A: Rule Creator != Rule Approver
        if ($rule->created_by !== null && (int) $rule->created_by === (int) $rule->approved_by) {
            return AutoPostEvaluationResult::blocked(
                $transaction->id,
                'SELF_APPROVED_RULE',
                "Rule Creator #{$rule->created_by} cannot approve their own rule.",
                $ruleId
            );
        }

        // 8B: Transaction Maker != Rule Approver
        // Check draft journal entry first
        $draft = $this->findLinkedDraft($transaction);
        $makerId = $draft?->created_by;

        if (! $makerId && $transaction->bank_transaction_id) {
            $bankTx = DB::table('bank_transactions')->where('id', $transaction->bank_transaction_id)->first();
            if ($bankTx && $bankTx->bank_import_id) {
                $import = DB::table('bank_imports')->where('id', $bankTx->bank_import_id)->first();
                $makerId = $import?->imported_by;
            }
        }

        if ($makerId && (int) $makerId === (int) $rule->approved_by) {
            return AutoPostEvaluationResult::blocked(
                $transaction->id,
                'TRANSACTION_MAKER_IS_RULE_APPROVER',
                "Transaction Maker #{$makerId} cannot be the Rule Approver #{$rule->approved_by}.",
                $ruleId
            );
        }

        // 9. Fiscal Period Open & Lock Guard
        if ($transaction->is_period_locked) {
            return AutoPostEvaluationResult::blocked(
                $transaction->id,
                'PERIOD_LOCKED',
                "Transaction #{$transaction->id} is period locked (is_period_locked = true).",
                $ruleId
            );
        }

        $fiscalPeriod = null;
        if ($transaction->fiscal_period_id) {
            $fiscalPeriod = FiscalPeriod::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('id', $transaction->fiscal_period_id)
                ->first();
        }

        if (! $fiscalPeriod) {
            $fiscalPeriod = FiscalPeriod::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->forDate($txDate)
                ->first();
        }

        if (! $fiscalPeriod || ! $fiscalPeriod->isOpen()) {
            return AutoPostEvaluationResult::blocked(
                $transaction->id,
                'CLOSED_FISCAL_PERIOD',
                "Fiscal period is closed or inactive for date {$txDate}.",
                $ruleId
            );
        }

        // 10. Existing Draft Journal Verification (if present)
        if ($draft) {
            if ($draft->isPosted()) {
                return AutoPostEvaluationResult::alreadyPosted($transaction->id, $draft->id);
            }

            // Balanced verification
            $debitSum = DB::table('journal_lines')
                ->where('journal_entry_id', $draft->id)
                ->sum('debit');
            $creditSum = DB::table('journal_lines')
                ->where('journal_entry_id', $draft->id)
                ->sum('credit');

            $debitMoney = Money::of((string) $debitSum);
            $creditMoney = Money::of((string) $creditSum);

            if (! $debitMoney->isPositive() || ! $debitMoney->equals($creditMoney)) {
                return AutoPostEvaluationResult::blocked(
                    $transaction->id,
                    'UNBALANCED_JOURNAL_DRAFT',
                    "Linked journal draft #{$draft->id} is unbalanced (Dr: {$debitMoney->getAmount()}, Cr: {$creditMoney->getAmount()}).",
                    $ruleId,
                    ['journal_entry_id' => $draft->id]
                );
            }
        }

        // All 11 gates verified successfully!
        return AutoPostEvaluationResult::eligible(
            $transaction->id,
            $rule->id,
            $draft?->id,
            'Transaction deterministically satisfies all 11 auto-post gates.',
            ['rule_version' => $rule->version, 'rule_approver_id' => $rule->approved_by]
        );
    }

    /**
     * Query for candidate selection: Tenant-scoped, unposted, positive amount, non-blocked categories.
     */
    public function getEligibleCandidatesQuery(int $organizationId): Builder
    {
        return BusinessTransaction::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('posted_journal_entry_id')
            ->whereIn('status', ['draft', 'approved'])
            ->where('auto_post_eligible', true)
            ->where('classification_status', 'classified')
            ->whereNotIn('transaction_type', ['RECEIVABLE_PAYMENT', 'UNAPPLIED_CASH'])
            ->where(function (Builder $query) {
                // If bank transfer, only allow already paired ones
                $query->where('transaction_type', '!=', 'BANK_TRANSFER')
                    ->orWhere('transfer_pairing_status', 'PAIRED');
            })
            ->where('amount', '>', 0)
            ->whereNull('posting_error')
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');
    }

    /**
     * Finds linked draft journal entry for a transaction.
     */
    public function findLinkedDraft(BusinessTransaction $transaction): ?JournalEntry
    {
        $orgId = (int) $transaction->organization_id;

        $source = JournalEntrySource::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('source_type', JournalEntrySource::TYPE_BUSINESS_TRANSACTION)
            ->where('source_id', $transaction->id)
            ->first();

        if ($source) {
            return JournalEntry::withoutGlobalScopes()
                ->where('id', $source->journal_entry_id)
                ->first();
        }

        return JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('business_transaction_id', $transaction->id)
            ->where('status', JournalEntry::STATUS_DRAFT)
            ->first();
    }
}
