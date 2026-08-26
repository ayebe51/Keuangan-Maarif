<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Organization\Models\Role;
use App\Domain\Organization\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        $query = User::with(['organization', 'roles']);

        if (!$currentUser->isSuperAdmin()) {
            $query->where('organization_id', $currentUser->organization_id);
        } elseif (TenantContext::hasTenant()) {
            $query->where('organization_id', TenantContext::getTenantId());
        }

        $users = $query->orderBy('name')->get();

        return response()->json([
            'data' => $users,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();

        $user = User::with(['organization', 'roles.permissions'])->findOrFail($id);

        if (!$currentUser->isSuperAdmin() && (int)$currentUser->organization_id !== (int)$user->organization_id) {
            return response()->json([
                'message' => 'Akses ditolak: Anda tidak memiliki izin melihat user organisasi lain.',
            ], 403);
        }

        return response()->json([
            'data' => $user,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        $validated = $request->validate([
            'organization_id' => [
                $currentUser->isSuperAdmin() ? 'required' : 'nullable',
                'integer',
                'exists:organizations,id',
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $targetOrgId = $currentUser->isSuperAdmin()
            ? ($validated['organization_id'] ?? TenantContext::getTenantId())
            : $currentUser->organization_id;

        $user = User::create([
            'organization_id' => $targetOrgId,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (!empty($validated['roles'])) {
            $user->syncRoles($validated['roles']);
        }

        AuditService::log(
            action: 'USER_CREATE',
            modelType: User::class,
            modelId: $user->id,
            newValues: $user->toArray(),
            notes: 'Pembuatan user baru: ' . $user->email,
            organizationId: $targetOrgId
        );

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'data' => $user->load(['organization', 'roles']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        $user = User::findOrFail($id);

        if (!$currentUser->isSuperAdmin() && (int)$currentUser->organization_id !== (int)$user->organization_id) {
            return response()->json([
                'message' => 'Akses ditolak: Anda tidak memiliki izin mengedit user organisasi lain.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['nullable', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $oldValues = $user->toArray();

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }
        if (isset($validated['email'])) {
            $user->email = $validated['email'];
        }
        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        if (array_key_exists('phone', $validated)) {
            $user->phone = $validated['phone'];
        }
        if (array_key_exists('is_active', $validated)) {
            $user->is_active = $validated['is_active'];
        }

        $user->save();

        if (array_key_exists('roles', $validated)) {
            $user->syncRoles($validated['roles'] ?? []);
        }

        AuditService::log(
            action: 'USER_UPDATE',
            modelType: User::class,
            modelId: $user->id,
            oldValues: $oldValues,
            newValues: $user->toArray(),
            notes: 'Update data user: ' . $user->email,
            organizationId: $user->organization_id
        );

        return response()->json([
            'message' => 'User berhasil diperbarui.',
            'data' => $user->load(['organization', 'roles']),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        $user = User::findOrFail($id);

        if (!$currentUser->isSuperAdmin() && (int)$currentUser->organization_id !== (int)$user->organization_id) {
            return response()->json([
                'message' => 'Akses ditolak: Anda tidak memiliki izin menghapus user organisasi lain.',
            ], 403);
        }

        if ((int)$currentUser->id === (int)$user->id) {
            return response()->json([
                'message' => 'Akses ditolak: Anda tidak dapat menghapus akun Anda sendiri.',
            ], 422);
        }

        $oldValues = $user->toArray();
        $user->delete();

        AuditService::log(
            action: 'USER_DELETE',
            modelType: User::class,
            modelId: $user->id,
            oldValues: $oldValues,
            notes: 'Penghapusan user: ' . $user->email,
            organizationId: $user->organization_id
        );

        return response()->json([
            'message' => 'User berhasil dihapus.',
        ]);
    }
}
