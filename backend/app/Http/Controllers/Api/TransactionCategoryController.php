<?php

namespace App\Http\Controllers\Api;

use App\Domain\Classification\Models\TransactionCategory;
use App\Domain\Classification\Services\TransactionCategoryService;
use App\Domain\Organization\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionCategoryController extends Controller
{
    public function __construct(
        protected TransactionCategoryService $transactionCategoryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $query = TransactionCategory::where('organization_id', $tenantId)
            ->with(['debitAccount', 'creditAccount']);

        if ($request->has('direction')) {
            $query->where('direction', strtoupper($request->query('direction')));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $categories = $query->orderBy('direction')->orderBy('code')->get();

        return response()->json(['data' => $categories]);
    }

    public function show(int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $category = TransactionCategory::where('organization_id', $tenantId)
            ->with(['debitAccount', 'creditAccount'])
            ->findOrFail($id);

        return response()->json(['data' => $category]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'direction' => ['required', 'string', 'in:IN,OUT,TRANSFER'],
            'default_debit_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'default_credit_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['organization_id'] = $tenantId;

        $category = $this->transactionCategoryService->create($validated, $request->user());

        return response()->json([
            'message' => 'Kategori transaksi berhasil dibuat.',
            'data' => $category->load(['debitAccount', 'creditAccount']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $category = TransactionCategory::where('organization_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'direction' => ['sometimes', 'required', 'string', 'in:IN,OUT,TRANSFER'],
            'default_debit_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'default_credit_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $updated = $this->transactionCategoryService->update($category, $validated, $request->user());

        return response()->json([
            'message' => 'Kategori transaksi berhasil diperbarui.',
            'data' => $updated->load(['debitAccount', 'creditAccount']),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $category = TransactionCategory::where('organization_id', $tenantId)->findOrFail($id);

        $this->transactionCategoryService->delete($category, $request->user());

        return response()->json([
            'message' => 'Kategori transaksi berhasil dihapus.',
        ]);
    }
}
