<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Role;
use App\Domain\Organization\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string'],
        ]);

        $user = User::with(['organization', 'roles.permissions'])->where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Kredensial yang diberikan tidak cocok dengan data kami.'],
            ]);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Akun Anda dinonaktifkan. Silakan hubungi administrator.',
            ], 403);
        }

        if ($user->organization && !$user->organization->is_active && !$user->isSuperAdmin()) {
            return response()->json([
                'message' => 'Organisasi Anda sedang dinonaktifkan. Silakan hubungi administrator.',
            ], 403);
        }

        $deviceName = $validated['device_name'] ?? 'API Client';
        $token = $user->createToken($deviceName)->plainTextToken;

        $user->update(['last_login_at' => now()]);

        AuditService::log(
            action: 'AUTH_LOGIN',
            modelType: User::class,
            modelId: $user->id,
            notes: 'Login berhasil via ' . $deviceName,
            userId: $user->id,
            organizationId: $user->organization_id
        );

        return response()->json([
            'message' => 'Login berhasil.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'organization' => $user->organization ? [
                    'id' => $user->organization->id,
                    'code' => $user->organization->code,
                    'name' => $user->organization->name,
                ] : null,
                'roles' => $user->roles->pluck('name'),
                'permissions' => $user->getAllPermissions()->pluck('name')->unique()->values(),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user()->load(['organization', 'roles.permissions']);

        $activeOrg = TenantContext::getTenant() ?? $user->organization;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'organization' => $user->organization ? [
                    'id' => $user->organization->id,
                    'code' => $user->organization->code,
                    'name' => $user->organization->name,
                    'type' => $user->organization->type,
                ] : null,
                'active_organization' => $activeOrg ? [
                    'id' => $activeOrg->id,
                    'code' => $activeOrg->code,
                    'name' => $activeOrg->name,
                ] : null,
                'roles' => $user->roles->pluck('name'),
                'permissions' => $user->getAllPermissions()->pluck('name')->unique()->values(),
            ],
        ]);
    }

    public function switchOrganization(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (!$user->isSuperAdmin()) {
            return response()->json([
                'message' => 'Hanya SUPER_ADMIN yang memiliki izin untuk berpindah konteks organisasi.',
            ], 403);
        }

        $validated = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
        ]);

        $org = Organization::findOrFail($validated['organization_id']);

        return response()->json([
            'message' => 'Konteks organisasi berhasil diubah.',
            'organization' => [
                'id' => $org->id,
                'code' => $org->code,
                'name' => $org->name,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();

            AuditService::log(
                action: 'AUTH_LOGOUT',
                modelType: User::class,
                modelId: $user->id,
                notes: 'Logout berhasil',
                userId: $user->id,
                organizationId: $user->organization_id
            );
        }

        return response()->json([
            'message' => 'Logout berhasil.',
        ]);
    }
}
