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
