<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Accounting\Services\AccountService;
use App\Domain\Organization\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(
        protected AccountService $accountService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $query = Account::where('organization_id', $tenantId);

        if ($request->has('account_type')) {
            $query->where('account_type', $request->query('account_type'));
        }

        if ($request->has('is_postable')) {
            $query->where('is_postable', filter_var($request->query('is_postable'), FILTER_VALIDATE_BOOLEAN));
        }

        $accounts = $query->orderBy('sort_order')->orderBy('code')->get();

        return response()->json(['data' => $accounts]);
    }

    public function tree(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $tree = $this->accountService->getTree($tenantId);

        return response()->json(['data' => $tree]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'account_type' => ['required', 'string', 'in:asset,liability,equity,revenue,expense'],
            'normal_balance' => ['nullable', 'string', 'in:debit,credit'],
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'is_postable' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $validated['organization_id'] = $tenantId;

        $account = $this->accountService->createAccount($validated, $request->user());

        return response()->json([
            'message' => 'Akun COA berhasil dibuat.',
            'data' => $account,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $account = Account::where('organization_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'is_postable' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $updated = $this->accountService->updateAccount($account, $validated, $request->user());

        return response()->json([
            'message' => 'Akun COA berhasil diperbarui.',
            'data' => $updated,
        ]);
    }

    public function mappings(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $mappings = AccountMapping::where('organization_id', $tenantId)
            ->with(['account'])
            ->orderBy('mapping_type')
            ->get();

        return response()->json(['data' => $mappings]);
    }

    public function storeMapping(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'mapping_type' => ['required', 'string', 'max:50'],
            'mapping_key' => ['required', 'string', 'max:100'],
            'mapping_value' => ['nullable', 'string', 'max:100'],
        ]);

        // Ensure account belongs to tenant
        $this->accountService->ensurePostable($validated['account_id'], $tenantId);

        $mapping = AccountMapping::updateOrCreate(
            [
                'organization_id' => $tenantId,
                'mapping_type' => $validated['mapping_type'],
                'mapping_key' => $validated['mapping_key'],
                'account_id' => $validated['account_id'],
            ],
            [
                'mapping_value' => $validated['mapping_value'] ?? null,
                'is_active' => true,
            ]
        );

        return response()->json([
            'message' => 'Mapping akun berhasil disimpan.',
            'data' => $mapping,
        ], 201);
    }
}
