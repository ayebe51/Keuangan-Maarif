<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Services\FiscalPeriodService;
use App\Domain\Organization\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiscalPeriodController extends Controller
{
    public function __construct(
        protected FiscalPeriodService $periodService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $periods = FiscalPeriod::where('organization_id', $tenantId)
            ->orderByDesc('start_date')
            ->get();

        return response()->json(['data' => $periods]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:100'],
            'period_type' => ['nullable', 'string', 'in:annual,quarterly,monthly'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_current' => ['nullable', 'boolean'],
        ]);

        $validated['organization_id'] = $tenantId;

        $period = $this->periodService->createPeriod($validated, $request->user());

        return response()->json([
            'message' => 'Periode fiskal berhasil dibuat.',
            'data' => $period,
        ], 201);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $period = FiscalPeriod::where('organization_id', $tenantId)->findOrFail($id);

        $closed = $this->periodService->closePeriod($period, $request->user());

        return response()->json([
            'message' => 'Periode fiskal berhasil ditutup.',
            'data' => $closed,
        ]);
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $period = FiscalPeriod::where('organization_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $reopened = $this->periodService->reopenPeriod($period, $request->user(), $validated['reason']);

        return response()->json([
            'message' => 'Periode fiskal berhasil dibuka kembali.',
            'data' => $reopened,
        ]);
    }
}
