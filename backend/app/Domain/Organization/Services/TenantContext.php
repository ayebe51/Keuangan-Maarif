<?php

namespace App\Domain\Organization\Services;

use App\Domain\Organization\Models\Organization;
use Closure;

class TenantContext
{
    protected static ?int $tenantId = null;
    protected static ?Organization $tenant = null;
    protected static bool $bypassScoping = false;

    public static function setTenantId(?int $tenantId): void
    {
        static::$tenantId = $tenantId;
        if ($tenantId === null) {
            static::$tenant = null;
        }
    }

    public static function getTenantId(): ?int
    {
        return static::$tenantId;
    }

    public static function setTenant(?Organization $organization): void
    {
        static::$tenant = $organization;
        static::$tenantId = $organization?->id;
    }

    public static function getTenant(): ?Organization
    {
        if (static::$tenant === null && static::$tenantId !== null) {
            static::$tenant = Organization::find(static::$tenantId);
        }

        return static::$tenant;
    }

    public static function hasTenant(): bool
    {
        return static::$tenantId !== null;
    }

    public static function isScopingBypassed(): bool
    {
        return static::$bypassScoping;
    }

    public static function setBypassScoping(bool $bypass): void
    {
        static::$bypassScoping = $bypass;
    }

    public static function withoutTenant(Closure $callback): mixed
    {
        $previousTenantId = static::$tenantId;
        $previousTenant = static::$tenant;
        $previousBypass = static::$bypassScoping;

        static::$bypassScoping = true;

        try {
            return $callback();
        } finally {
            static::$tenantId = $previousTenantId;
            static::$tenant = $previousTenant;
            static::$bypassScoping = $previousBypass;
        }
    }

    public static function forTenant(int $tenantId, Closure $callback): mixed
    {
        $previousTenantId = static::$tenantId;
        $previousTenant = static::$tenant;
        $previousBypass = static::$bypassScoping;

        static::$tenantId = $tenantId;
        static::$tenant = null;
        static::$bypassScoping = false;

        try {
            return $callback();
        } finally {
            static::$tenantId = $previousTenantId;
            static::$tenant = $previousTenant;
            static::$bypassScoping = $previousBypass;
        }
    }

    public static function reset(): void
    {
        static::$tenantId = null;
        static::$tenant = null;
        static::$bypassScoping = false;
    }
}
