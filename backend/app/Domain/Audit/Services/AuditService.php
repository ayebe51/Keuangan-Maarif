<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organization\Services\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditService
{
    public static function log(
        string $action,
        ?string $modelType = null,
        ?int $modelId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $notes = null,
        ?int $userId = null,
        ?int $organizationId = null
    ): AuditLog {
        $user = Auth::user();
        $finalUserId = $userId ?? $user?->id;
        $finalOrgId = $organizationId ?? TenantContext::getTenantId() ?? $user?->organization_id;

        return AuditLog::create([
            'organization_id' => $finalOrgId,
            'user_id' => $finalUserId,
            'action' => $action,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'session_id' => session()->getId() ?: Request::header('X-Correlation-ID'),
            'notes' => $notes,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Log an automated system action executed by SYSTEM_DAEMON without user impersonation.
     * Guaranteed user_id = null (zero identity spoofing).
     */
    public static function logSystem(
        string $action,
        ?string $modelType = null,
        ?int $modelId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $notes = null,
        ?int $organizationId = null
    ): AuditLog {
        $finalOrgId = $organizationId ?? TenantContext::getTenantId();

        return AuditLog::create([
            'organization_id' => $finalOrgId,
            'user_id' => null,
            'action' => $action,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip() ?? '127.0.0.1',
            'user_agent' => Request::userAgent() ?? 'SYSTEM_DAEMON',
            'session_id' => session()->getId() ?: Request::header('X-Correlation-ID') ?: 'SYSTEM',
            'notes' => $notes,
            'occurred_at' => now(),
        ]);
    }
}
