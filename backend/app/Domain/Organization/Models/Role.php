<?php

namespace App\Domain\Organization\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    // List of canonical system roles
    public const SUPER_ADMIN = 'SUPER_ADMIN';
    public const ACCOUNTING_ADMIN = 'ACCOUNTING_ADMIN';
    public const ACCOUNTING_OPERATOR = 'ACCOUNTING_OPERATOR';
    public const RECONCILER = 'RECONCILER';
    public const APPROVER = 'APPROVER';
    public const REPORT_VIEWER = 'REPORT_VIEWER';
    public const AUDITOR = 'AUDITOR';

    protected $fillable = [
        'name',
        'guard_name',
        'organization_id',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function isGlobal(): bool
    {
        return $this->organization_id === null;
    }
}
