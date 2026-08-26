<?php

namespace App\Http\Middleware;

use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::reset();

        $user = $request->user();

        if ($user) {
            $headerOrgId = $request->header('X-Organization-ID') ?? $request->input('organization_id');

            if ($user->isSuperAdmin()) {
                if ($headerOrgId !== null && is_numeric($headerOrgId)) {
                    $org = Organization::find((int)$headerOrgId);
                    if ($org) {
                        TenantContext::setTenant($org);
                    } else {
                        return response()->json([
                            'message' => 'Organisasi yang diminta tidak ditemukan.',
                        ], 404);
                    }
                } elseif ($user->organization_id) {
                    TenantContext::setTenantId((int)$user->organization_id);
                } else {
                    // Super admin without specific organization bypasses scoping by default
                    TenantContext::setBypassScoping(true);
                }
            } else {
                // Non-superadmin user is strictly bound to their organization
                $userOrgId = (int)$user->organization_id;

                if ($headerOrgId !== null && (int)$headerOrgId !== $userOrgId) {
                    throw new CrossTenantViolationException(
                        'Akses ditolak: Anda tidak memiliki izin untuk mengakses data organisasi lain.'
                    );
                }

                if ($userOrgId > 0) {
                    TenantContext::setTenantId($userOrgId);
                }
            }
        }

        $response = $next($request);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        TenantContext::reset();
    }
}
