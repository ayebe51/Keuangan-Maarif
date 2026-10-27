<?php

namespace App\Http\Controllers\Api;

use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Services\ClassificationRuleService;
use App\Domain\Organization\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassificationRuleController extends Controller
{
    public function __construct(
        protected ClassificationRuleService $ruleService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $query = ClassificationRule::where('organization_id', $tenantId)
            ->with(['targetAccount', 'transactionCategory', 'counterparty', 'fund', 'bankAccount']);

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('direction')) {
            $query->where('direction', strtoupper($request->query('direction')));
        }

        if ($request->filled('transaction_type')) {
            $query->where('transaction_type', $request->query('transaction_type'));
        }

        if ($request->filled('q')) {
            $search = '%' . $request->query('q') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('pattern', 'like', $search);
            });
        }

        $rules = $query->orderBy('priority', 'asc')->orderBy('id', 'asc')->get();

        return response()->json(['data' => $rules]);
    }

    public function show(int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $rule = ClassificationRule::where('organization_id', $tenantId)
            ->with(['targetAccount', 'transactionCategory', 'counterparty', 'fund', 'bankAccount'])
            ->findOrFail($id);

        return response()->json(['data' => $rule]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'priority' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'rule_type' => ['nullable', 'string', 'in:KEYWORD,REGEX,EXACT_REFERENCE,COUNTERPARTY'],
            'direction' => ['nullable', 'string', 'in:IN,OUT,TRANSFER,in,out,transfer'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'pattern' => ['required', 'string', 'max:500'],
            'pattern_type' => ['nullable', 'string', 'in:contains,exact,regex,starts_with,ends_with'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'transaction_type' => ['required', 'string'],
            'transaction_category_id' => ['nullable', 'integer', 'exists:transaction_categories,id'],
            'target_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'counterparty_id' => ['nullable', 'integer', 'exists:counterparties,id'],
            'fund_id' => ['nullable', 'integer', 'exists:funds,id'],
            'auto_post' => ['nullable', 'boolean'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'description' => ['nullable', 'string'],
        ]);

        $validated['organization_id'] = $tenantId;

        $rule = $this->ruleService->create($validated, $request->user());

        return response()->json(['data' => $rule], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $rule = ClassificationRule::where('organization_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'priority' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'rule_type' => ['nullable', 'string', 'in:KEYWORD,REGEX,EXACT_REFERENCE,COUNTERPARTY'],
            'direction' => ['nullable', 'string', 'in:IN,OUT,TRANSFER,in,out,transfer'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'pattern' => ['sometimes', 'string', 'max:500'],
            'pattern_type' => ['nullable', 'string', 'in:contains,exact,regex,starts_with,ends_with'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'transaction_type' => ['sometimes', 'string'],
            'transaction_category_id' => ['nullable', 'integer', 'exists:transaction_categories,id'],
            'target_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'counterparty_id' => ['nullable', 'integer', 'exists:counterparties,id'],
            'fund_id' => ['nullable', 'integer', 'exists:funds,id'],
            'auto_post' => ['nullable', 'boolean'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'description' => ['nullable', 'string'],
        ]);

        $updated = $this->ruleService->update($rule, $validated, $request->user());

        return response()->json(['data' => $updated]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $rule = ClassificationRule::where('organization_id', $tenantId)->findOrFail($id);

        $this->ruleService->delete($rule, $request->user());

        return response()->json(['message' => 'Classification rule deleted successfully.']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'rule_ids' => ['required', 'array'],
            'rule_ids.*' => ['integer'],
        ]);

        $this->ruleService->reorder($tenantId, $validated['rule_ids'], $request->user());

        return response()->json(['message' => 'Rules reordered successfully.']);
    }
}
