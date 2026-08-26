<?php

namespace App\Http\Middleware;

use App\Domain\Organization\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!TenantContext::hasTenant()) {
            return response()->json([
                'message' => 'Konteks organisasi (tenant) wajib ditentukan untuk operasi ini. Harap sertakan header X-Organization-ID atau login dengan user organisasi.',
            ], 400);
        }

        return $next($request);
    }
}
