<?php

namespace App\Http\Controllers\Api;

use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Models\ClassificationSuggestion;
use App\Domain\Classification\Services\TransactionClassificationService;
use App\Domain\Organization\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionClassificationController extends Controller
{
    public function __construct(
        protected TransactionClassificationService $classificationService
    ) {}

    public function classifyBatch(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'bank_transaction_ids' => ['required', 'array'],
            'bank_transaction_ids.*' => ['integer'],
        ]);

        // Validate that all transactions belong to current tenant
        $validIds = BankTransaction::where('organization_id', $tenantId)
            ->whereIn('id', $validated['bank_transaction_ids'])
            ->pluck('id')
            ->all();

        $results = $this->classificationService->classifyBatch($validIds, $request->user());

        return response()->json([
            'message' => 'Batch classification processed successfully.',
            'total_processed' => count($results),
            'data' => $results,
        ]);
    }

    public function classifySingle(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $tx = BankTransaction::where('organization_id', $tenantId)->findOrFail($id);

        $bizTx = $this->classificationService->classifyTransaction($tx, $request->user());

        return response()->json([
            'message' => 'Transaction classified successfully.',
            'data' => $bizTx,
        ]);
    }

    public function getUnclassified(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $query = BankTransaction::where('organization_id', $tenantId)
            ->where(function ($q) {
                $q->where('classification_status', ClassificationStatus::UNCLASSIFIED->value)
                  ->orWhereNull('classification_status');
            })
            ->with(['bankAccount', 'counterparty']);

        if ($request->has('bank_account_id')) {
            $query->where('bank_account_id', $request->query('bank_account_id'));
        }

        if ($request->has('direction')) {
            $query->where('direction', strtoupper($request->query('direction')));
        }

        $perPage = min((int) ($request->query('per_page', 25)), 100);
        $transactions = $query->orderBy('transaction_date', 'desc')->paginate($perPage);

        return response()->json($transactions);
    }

    public function getSuggestions(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $query = ClassificationSuggestion::where('organization_id', $tenantId)
            ->where('status', ClassificationSuggestion::STATUS_PENDING)
            ->with(['bankTransaction', 'businessTransaction', 'suggestedAccount', 'suggestedCategory', 'suggestedCounterparty']);

        $perPage = min((int) ($request->query('per_page', 25)), 100);
        $suggestions = $query->orderBy('confidence', 'desc')->paginate($perPage);

        return response()->json($suggestions);
    }

    public function applySuggestion(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $suggestion = ClassificationSuggestion::where('organization_id', $tenantId)
            ->with('businessTransaction')
            ->findOrFail($id);

        $bizTx = $this->classificationService->applySuggestion($suggestion, $request->user());

        return response()->json([
            'message' => 'Suggestion accepted and applied.',
            'data' => $bizTx,
        ]);
    }

    public function rejectSuggestion(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $suggestion = ClassificationSuggestion::where('organization_id', $tenantId)->findOrFail($id);

        $this->classificationService->rejectSuggestion($suggestion, $request->user());

        return response()->json([
            'message' => 'Suggestion rejected.',
        ]);
    }

    public function manualClassify(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $tx = BankTransaction::where('organization_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'transaction_type' => ['required', 'string'],
            'target_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'counterparty_id' => ['nullable', 'integer', 'exists:counterparties,id'],
            'transaction_category_id' => ['nullable', 'integer', 'exists:transaction_categories,id'],
            'fund_id' => ['nullable', 'integer', 'exists:funds,id'],
            'description' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
            'save_as_rule' => ['nullable', 'boolean'],
            'rule_pattern' => ['nullable', 'string', 'max:500'],
        ]);

        $bizTx = $this->classificationService->manualClassify($tx, $validated, $request->user());

        return response()->json([
            'message' => 'Transaction manually classified.',
            'data' => $bizTx,
        ]);
    }
}
