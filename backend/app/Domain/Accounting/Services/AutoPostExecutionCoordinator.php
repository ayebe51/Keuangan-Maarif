<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DTOs\AutomationPostingAuthorization;
use App\Domain\Accounting\Enums\AutoPostDecisionOutcome;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AutoPostExecutionCoordinator
{
    public function __construct(
        protected JournalPostingService $journalPostingService,
        protected AutoPostEligibilityPolicy $eligibilityPolicy,
        protected JournalDraftGenerator $draftGenerator
    ) {}

    /**
     * Executes automated posting for a single BusinessTransaction.
     * Enforces row locking, re-evaluates eligibility policy, generates draft if needed,
     * and delegates to the single accounting core postSystemAutomated().
     *
     * @return array{
     *     success: bool,
     *     outcome: string,
     *     is_duplicate: bool,
     *     message: string,
     *     reason_code?: string,
     *     data: array,
     *     transaction?: BusinessTransaction,
     *     journal_entry?: JournalEntry|null
     * }
     */
    public function execute(BusinessTransaction|int $transaction): array
    {
        return DB::transaction(function () use ($transaction) {
            $txId = $transaction instanceof BusinessTransaction ? $transaction->id : (int) $transaction;

            $tx = BusinessTransaction::withoutGlobalScopes()
                ->where('id', $txId)
                ->lockForUpdate()
                ->firstOrFail();

            $orgId = (int) $tx->organization_id;

            // Tenant Isolation Guard
            if (TenantContext::hasTenant() && (int) TenantContext::getTenantId() !== $orgId) {
                throw new CrossTenantViolationException(
                    "BusinessTransaction {$tx->transaction_number} belongs to organization {$orgId}, which does not match active tenant."
                );
            }

            // Idempotency: if already posted, return idempotent success
            if ($tx->isPosted() || $tx->posted_journal_entry_id !== null) {
                $postedJournal = $tx->postedJournalEntry
                    ?? JournalEntry::withoutGlobalScopes()->find($tx->posted_journal_entry_id);

                return [
                    'success' => true,
                    'outcome' => AutoPostDecisionOutcome::ALREADY_POSTED->value,
                    'is_duplicate' => true,
                    'message' => 'Transaksi ini telah diposting sebelumnya ke buku besar.',
                    'data' => [
                        'business_transaction_id' => $tx->id,
                        'journal_entry_id' => $postedJournal?->id,
                        'entry_number' => $postedJournal?->entry_number,
                        'posted_at' => $tx->posted_at?->toISOString() ?? $postedJournal?->posted_at?->toISOString(),
                    ],
                    'transaction' => $tx,
                    'journal_entry' => $postedJournal,
                ];
            }

            // Evaluate Eligibility Policy (11 Gates + Domain Boundaries)
            $eval = $this->eligibilityPolicy->evaluate($tx);

            if (! $eval->isEligible()) {
                // Record structured error on transaction if blocked or permanent failure
                if (in_array($eval->outcome, [AutoPostDecisionOutcome::BLOCKED, AutoPostDecisionOutcome::PERMANENT_FAILURE], true)) {
                    $tx->posting_error = [
                        'error_code' => $eval->reasonCode,
                        'message' => $eval->message,
                        'occurred_at' => now()->toISOString(),
                        'context' => $eval->context,
                    ];
                    $tx->save();
                }

                return [
                    'success' => false,
                    'outcome' => $eval->outcome->value,
                    'is_duplicate' => false,
                    'reason_code' => $eval->reasonCode,
                    'message' => $eval->message,
                    'data' => [
                        'business_transaction_id' => $tx->id,
                        'classification_rule_id' => $eval->classificationRuleId,
                    ],
                    'transaction' => $tx->fresh(),
                ];
            }

            // Ensure Draft Journal exists
            $draft = $this->eligibilityPolicy->findLinkedDraft($tx);

            if (! $draft) {
                $maker = $this->resolveMakerForDraft($tx);
                $draft = $this->draftGenerator->generate($tx, $maker);
            }

            // Lock ClassificationRule
            $rule = ClassificationRule::withoutGlobalScopes()
                ->where('id', $eval->classificationRuleId)
                ->lockForUpdate()
                ->firstOrFail();

            // Construct server-authoritative envelope
            $auth = AutomationPostingAuthorization::fromEntities($draft, $tx, $rule, [
                'trigger' => 'auto_post_execution_engine',
                'evaluation_reason' => $eval->reasonCode,
            ]);

            // Delegate to Phase 3 Single Accounting Core
            $postedJournal = $this->journalPostingService->postSystemAutomated($draft, $auth);

            return [
                'success' => true,
                'outcome' => AutoPostDecisionOutcome::ELIGIBLE->value,
                'is_duplicate' => false,
                'message' => 'Transaksi berhasil diposting otomatis ke buku besar.',
                'data' => [
                    'business_transaction_id' => $tx->id,
                    'journal_entry_id' => $postedJournal->id,
                    'entry_number' => $postedJournal->entry_number,
                    'posted_at' => $postedJournal->posted_at?->toISOString() ?? now()->toISOString(),
                    'rule_id' => $rule->id,
                ],
                'transaction' => $tx->fresh(['postedJournalEntry', 'bankTransaction']),
                'journal_entry' => $postedJournal,
            ];
        });
    }

    /**
     * Executes automated posting in batch for eligible candidates within a tenant.
     * Enforces deterministic candidate ordering, per-candidate transaction isolation,
     * and structured telemetry collection.
     *
     * @return array{
     *     organization_id: int,
     *     total_candidates: int,
     *     processed_count: int,
     *     success_count: int,
     *     failed_count: int,
     *     skipped_count: int,
     *     results: array
     * }
     */
    public function executeBatch(int $organizationId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));

        // Deterministic candidate selection
        $candidateIds = $this->eligibilityPolicy->getEligibleCandidatesQuery($organizationId)
            ->limit($limit)
            ->pluck('id')
            ->all();

        $telemetry = [
            'organization_id' => $organizationId,
            'total_candidates' => count($candidateIds),
            'processed_count' => 0,
            'success_count' => 0,
            'posted_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
            'results' => [],
        ];

        foreach ($candidateIds as $txId) {
            $telemetry['processed_count']++;

            try {
                $result = $this->execute($txId);

                if ($result['success']) {
                    if ($result['is_duplicate'] ?? false) {
                        $telemetry['skipped_count']++;
                    } else {
                        $telemetry['success_count']++;
                        $telemetry['posted_count']++;
                    }
                } else {
                    if (in_array($result['outcome'] ?? '', [
                        AutoPostDecisionOutcome::REQUIRES_HUMAN_REVIEW->value,
                        AutoPostDecisionOutcome::INELIGIBLE->value,
                    ], true)) {
                        $telemetry['skipped_count']++;
                    } else {
                        $telemetry['failed_count']++;
                    }
                }

                $telemetry['results'][] = [
                    'business_transaction_id' => $txId,
                    'success' => $result['success'],
                    'outcome' => $result['outcome'],
                    'reason_code' => $result['reason_code'] ?? null,
                    'message' => $result['message'],
                    'journal_entry_id' => $result['data']['journal_entry_id'] ?? null,
                ];
            } catch (\Throwable $e) {
                // Per-candidate failure isolation: error does not rollback other candidates
                $telemetry['failed_count']++;
                $telemetry['results'][] = [
                    'business_transaction_id' => $txId,
                    'success' => false,
                    'outcome' => AutoPostDecisionOutcome::RETRYABLE_FAILURE->value,
                    'reason_code' => 'UNHANDLED_EXCEPTION',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $telemetry;
    }

    /**
     * Resolves an authorized human maker user for draft generation if needed.
     */
    protected function resolveMakerForDraft(BusinessTransaction $tx): User
    {
        // 1. Check user who imported the bank transaction
        if ($tx->bank_transaction_id) {
            $bankTx = DB::table('bank_transactions')->where('id', $tx->bank_transaction_id)->first();
            if ($bankTx && $bankTx->bank_import_id) {
                $import = DB::table('bank_imports')->where('id', $bankTx->bank_import_id)->first();
                if ($import && $import->imported_by) {
                    $user = User::withoutGlobalScopes()->find($import->imported_by);
                    if ($user && $user->is_active) {
                        return $user;
                    }
                }
            }
        }

        // 2. Check for an active operator in the organization
        $operator = User::withoutGlobalScopes()
            ->where('organization_id', $tx->organization_id)
            ->where('is_active', true)
            ->where('email', 'LIKE', '%operator%')
            ->first();

        if ($operator) {
            return $operator;
        }

        // 3. Fallback: Any active user in the organization who is NOT the rule approver
        $approverId = $tx->rule_snapshot['approved_by'] ?? null;
        $fallback = User::withoutGlobalScopes()
            ->where('organization_id', $tx->organization_id)
            ->where('is_active', true)
            ->when($approverId, fn ($q) => $q->where('id', '!=', $approverId))
            ->first();

        if ($fallback) {
            return $fallback;
        }

        throw new RuntimeException("Unable to resolve a valid transaction maker for BusinessTransaction #{$tx->id}.");
    }
}
