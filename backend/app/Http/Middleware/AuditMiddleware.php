<?php

namespace App\Http\Middleware;

use App\Domain\Audit\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuditMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-ID') ?: (string) Str::uuid();
        $request->headers->set('X-Correlation-ID', $correlationId);

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);

        // Record audit log for mutating HTTP actions on success (2xx, 3xx)
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $response->getStatusCode() < 400) {
            $user = $request->user();
            if ($user) {
                $filteredInput = $request->except(['password', 'password_confirmation', 'token', 'secret']);
                AuditService::log(
                    action: 'HTTP_' . $request->method(),
                    notes: 'Route: ' . $request->path() . ' (Status: ' . $response->getStatusCode() . ')',
                    newValues: !empty($filteredInput) ? $filteredInput : null
                );
            }
        }

        return $response;
    }
}
