<?php

namespace App\Domain\Organization\Scopes;

use App\Domain\Organization\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (TenantContext::isScopingBypassed()) {
            return;
        }

        if (TenantContext::hasTenant()) {
            $builder->where($model->qualifyColumn('organization_id'), TenantContext::getTenantId());
        }
    }
}
