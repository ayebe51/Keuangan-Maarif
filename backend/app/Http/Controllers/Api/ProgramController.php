<?php

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Services\TenantContext;
use App\Domain\Program\Models\Program;
use App\Domain\Program\Services\ProgramService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgramController extends Controller
{
    public function __construct(
        protected ProgramService $programService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $query = Program::where('organization_id', $tenantId)->with(['allocations']);

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('q')) {
            $search = '%' . $request->query('q') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', $search)
                  ->orWhere('code', 'ilike', $search);
            });
        }

        $programs = $query->orderBy('code')->get();

        return response()->json(['data' => $programs]);
    }

    public function show(int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $program = Program::where('organization_id', $tenantId)
            ->with(['allocations.fund'])
            ->findOrFail($id);

        return response()->json(['data' => $program]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['organization_id'] = $tenantId;

        $program = $this->programService->create($validated, $request->user());

        return response()->json([
            'message' => 'Program berhasil dibuat.',
            'data' => $program,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $program = Program::where('organization_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $updated = $this->programService->update($program, $validated, $request->user());

        return response()->json([
            'message' => 'Program berhasil diperbarui.',
            'data' => $updated,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $program = Program::where('organization_id', $tenantId)->findOrFail($id);

        $this->programService->delete($program, $request->user());

        return response()->json([
            'message' => 'Program berhasil dihapus.',
        ]);
    }
}
