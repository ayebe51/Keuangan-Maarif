<?php

namespace App\Http\Controllers\Api;

use App\Domain\Fund\Models\Fund;
use App\Domain\Fund\Models\FundAllocation;
use App\Domain\Fund\Services\FundService;
use App\Domain\Organization\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FundController extends Controller
{
    public function __construct(
        protected FundService $fundService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $query = Fund::where('organization_id', $tenantId)->with(['account', 'allocations']);

        if ($request->has('fund_type')) {
            $query->where('fund_type', $request->query('fund_type'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $funds = $query->orderBy('code')->get();

        return response()->json(['data' => $funds]);
    }

    public function show(int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $fund = Fund::where('organization_id', $tenantId)
            ->with(['account', 'allocations.fiscalPeriod'])
            ->findOrFail($id);

        $summary = $this->fundService->getSummary($fund);

        return response()->json([
            'data' => $fund,
            'summary' => $summary,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'fund_type' => ['nullable', 'string', 'in:' . implode(',', Fund::ALL_TYPES)],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:' . implode(',', Fund::ALL_STATUSES)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['organization_id'] = $tenantId;

        $fund = $this->fundService->createFund($validated, $request->user());

        return response()->json([
            'message' => 'Master dana (fund) berhasil dibuat.',
            'data' => $fund->load('account'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $fund = Fund::where('organization_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'fund_type' => ['nullable', 'string', 'in:' . implode(',', Fund::ALL_TYPES)],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:' . implode(',', Fund::ALL_STATUSES)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $updated = $this->fundService->updateFund($fund, $validated, $request->user());

        return response()->json([
            'message' => 'Data dana berhasil diperbarui.',
            'data' => $updated->load('account'),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $fund = Fund::where('organization_id', $tenantId)->findOrFail($id);

        $this->fundService->deleteFund($fund, $request->user());

        return response()->json([
            'message' => 'Dana berhasil dihapus.',
        ]);
    }

    public function allocate(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $fund = Fund::where('organization_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'fiscal_period_id' => ['required', 'integer', 'exists:fiscal_periods,id'],
            'allocated_amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string'],
        ]);

        $validated['organization_id'] = $tenantId;
        $validated['fund_id'] = $fund->id;

        $allocation = $this->fundService->createAllocation($validated, $request->user());

        return response()->json([
            'message' => 'Alokasi dana berhasil dibuat.',
            'data' => $allocation->load('fiscalPeriod'),
        ], 201);
    }

    public function commit(Request $request, int $allocationId): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $allocation = FundAllocation::where('organization_id', $tenantId)->findOrFail($allocationId);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $updated = $this->fundService->commit(
            $allocation,
            $validated['amount'],
            $request->user()
        );

        return response()->json([
            'message' => 'Komitmen dana berhasil dicatat.',
            'data' => $updated,
        ]);
    }

    public function disburse(Request $request, int $allocationId): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $allocation = FundAllocation::where('organization_id', $tenantId)->findOrFail($allocationId);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'from_committed' => ['nullable', 'boolean'],
        ]);

        $fromCommitted = filter_var($request->input('from_committed', false), FILTER_VALIDATE_BOOLEAN);

        $updated = $this->fundService->disburse(
            $allocation,
            $validated['amount'],
            $fromCommitted,
            $request->user()
        );

        return response()->json([
            'message' => 'Realisasi pencairan dana berhasil dicatat.',
            'data' => $updated,
        ]);
    }

    public function returnFunds(Request $request, int $allocationId): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $allocation = FundAllocation::where('organization_id', $tenantId)->findOrFail($allocationId);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $updated = $this->fundService->returnFunds(
            $allocation,
            $validated['amount'],
            $request->user()
        );

        return response()->json([
            'message' => 'Pengembalian sisa dana berhasil dicatat.',
            'data' => $updated,
        ]);
    }

    public function summary(int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $fund = Fund::where('organization_id', $tenantId)->findOrFail($id);

        $summary = $this->fundService->getSummary($fund);

        return response()->json(['data' => $summary]);
    }
}
