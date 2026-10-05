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

        return response()->json([
            'message' => 'Pihak ketiga (counterparty) berhasil didaftarkan.',
            'data' => $counterparty->load('aliases'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $counterparty = Counterparty::where('organization_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'role' => ['sometimes', 'required', 'string', 'in:' . implode(',', Counterparty::ALL_ROLES)],
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:50'],
            'npwp' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:100'],
            'address' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $updated = $this->counterpartyService->update($counterparty, $validated, $request->user());

        return response()->json([
            'message' => 'Data counterparty berhasil diperbarui.',
            'data' => $updated->load('aliases'),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $counterparty = Counterparty::where('organization_id', $tenantId)->findOrFail($id);

        $this->counterpartyService->delete($counterparty, $request->user());

        return response()->json([
            'message' => 'Counterparty berhasil dihapus.',
        ]);
    }

    public function addAlias(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $counterparty = Counterparty::where('organization_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'alias_name' => ['required', 'string', 'max:200'],
            'source' => ['nullable', 'string', 'in:manual,system,learned'],
        ]);

        $alias = $this->counterpartyService->addAlias(
            $counterparty,
            $validated['alias_name'],
            $validated['source'] ?? CounterpartyAlias::SOURCE_MANUAL,
            $request->user()
        );

        return response()->json([
            'message' => 'Alias counterparty berhasil ditambahkan.',
            'data' => $alias,
        ], 201);
    }

    public function removeAlias(Request $request, int $id, int $aliasId): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $counterparty = Counterparty::where('organization_id', $tenantId)->findOrFail($id);

        $alias = CounterpartyAlias::where('counterparty_id', $counterparty->id)
            ->where('organization_id', $tenantId)
            ->findOrFail($aliasId);

        $this->counterpartyService->removeAlias($alias, $request->user());

        return response()->json([
            'message' => 'Alias counterparty berhasil dihapus.',
        ]);
    }

    public function resolve(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $rawName = $request->input('name') ?? $request->query('name', '');

        if (!is_string($rawName) || trim($rawName) === '') {
            return response()->json([
                'message' => 'Parameter nama counterparty wajib disertakan.',
                'data' => null,
            ], 422);
        }

        $resolved = $this->counterpartyService->resolve($tenantId, $rawName);

        if (!$resolved) {
            return response()->json([
                'message' => 'Counterparty tidak ditemukan untuk string input yang diberikan.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'message' => 'Counterparty berhasil diidentifikasi.',
            'data' => $resolved->load('aliases'),
        ]);
    }
}
