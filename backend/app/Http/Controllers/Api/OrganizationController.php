<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            $organizations = Organization::orderBy('name')->get();
        } else {
            $organizations = Organization::where('id', $user->organization_id)->get();
        }

        return response()->json([
            'data' => $organizations,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user->isSuperAdmin() && (int)$user->organization_id !== $id) {
            return response()->json([
                'message' => 'Akses ditolak: Anda tidak memiliki izin melihat data organisasi ini.',
            ], 403);
        }

        $organization = Organization::withCount(['users'])->findOrFail($id);

        return response()->json([
            'data' => $organization,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->isSuperAdmin()) {
            return response()->json([
                'message' => 'Akses ditolak: Hanya SUPER_ADMIN yang dapat membuat organisasi baru.',
            ], 403);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:organizations,code'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'website' => ['nullable', 'string', 'max:200'],
            'npwp' => ['nullable', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $organization = Organization::create($validated);

        AuditService::log(
            action: 'ORGANIZATION_CREATE',
            modelType: Organization::class,
            modelId: $organization->id,
            newValues: $organization->toArray(),
            notes: 'Pembuatan organisasi baru: ' . $organization->name,
            organizationId: $organization->id
        );

        return response()->json([
            'message' => 'Organisasi berhasil dibuat.',
            'data' => $organization,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user->isSuperAdmin() && ((int)$user->organization_id !== $id || !$user->hasPermissionTo('organization.update'))) {
            return response()->json([
                'message' => 'Akses ditolak: Anda tidak memiliki izin mengubah data organisasi ini.',
            ], 403);
        }

        $organization = Organization::findOrFail($id);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:50', 'unique:organizations,code,' . $organization->id],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'website' => ['nullable', 'string', 'max:200'],
            'npwp' => ['nullable', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $oldValues = $organization->toArray();
        $organization->update($validated);

        AuditService::log(
            action: 'ORGANIZATION_UPDATE',
            modelType: Organization::class,
            modelId: $organization->id,
            oldValues: $oldValues,
            newValues: $organization->toArray(),
            notes: 'Update profil organisasi: ' . $organization->name,
            organizationId: $organization->id
        );

        return response()->json([
            'message' => 'Profil organisasi berhasil diperbarui.',
            'data' => $organization,
        ]);
    }
}
