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
}
