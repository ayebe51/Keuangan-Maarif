<?php

namespace App\Http\Controllers\Api;

use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Counterparty\Models\CounterpartyAlias;
use App\Domain\Counterparty\Services\CounterpartyService;
use App\Domain\Organization\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CounterpartyController extends Controller
{
    public function __construct(
        protected CounterpartyService $counterpartyService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $query = Counterparty::where('organization_id', $tenantId)->with(['aliases']);

        if ($request->has('role')) {
            $query->where('role', $request->query('role'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('q')) {
            $search = '%' . $request->query('q') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', $search)
                  ->orWhere('code', 'ilike', $search)
                  ->orWhereHas('aliases', function ($aq) use ($search) {
                      $aq->where('alias_name', 'ilike', $search);
                  });
            });
        }

        $counterparties = $query->orderBy('name')->get();

        return response()->json(['data' => $counterparties]);
    }

    public function show(int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $counterparty = Counterparty::where('organization_id', $tenantId)
            ->with(['aliases'])
            ->findOrFail($id);

        return response()->json(['data' => $counterparty]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:' . implode(',', Counterparty::ALL_ROLES)],
            'name' => ['required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:50'],
            'npwp' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:100'],
            'address' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'aliases' => ['nullable', 'array'],
            'aliases.*' => ['string', 'max:200'],
        ]);

        $validated['organization_id'] = $tenantId;

        $counterparty = $this->counterpartyService->create($validated, $request->user());
