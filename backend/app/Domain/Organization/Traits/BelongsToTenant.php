<?php

namespace App\Domain\Organization\Traits;

use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Scopes\TenantScope;
use App\Domain\Organization\Services\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model) {
            if (TenantContext::isScopingBypassed()) {
                return;
            }

            if (TenantContext::hasTenant()) {
                $currentTenantId = TenantContext::getTenantId();

                if (empty($model->organization_id)) {
                    $model->organization_id = $currentTenantId;
                } elseif ((int)$model->organization_id !== (int)$currentTenantId) {
                    throw new CrossTenantViolationException(
                        sprintf(
                            'Cross-tenant violation: Cannot create record for organization %d while in context of organization %d.',
                            $model->organization_id,
                            $currentTenantId
                        )
                    );
                }
            }
        });

        static::updating(function ($model) {
            if (TenantContext::isScopingBypassed()) {
                return;
            }

            if (TenantContext::hasTenant() && $model->isDirty('organization_id')) {
                $currentTenantId = TenantContext::getTenantId();
                if ((int)$model->organization_id !== (int)$currentTenantId) {
                    throw new CrossTenantViolationException(
                        'Cross-tenant violation: Cannot reassign record to a different organization.'
                    );
                }
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
