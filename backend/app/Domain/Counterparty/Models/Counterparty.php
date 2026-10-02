<?php

namespace App\Domain\Counterparty\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Counterparty extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes;

    public const ROLE_PAYER = 'payer';
    public const ROLE_PAYEE = 'payee';
    public const ROLE_SCHOOL = 'school';
    public const ROLE_BANK = 'bank';
    public const ROLE_VENDOR = 'vendor';
    public const ROLE_DONOR = 'donor';
    public const ROLE_GOVERNMENT = 'government';
    public const ROLE_INTERNAL = 'internal';

    public const ALL_ROLES = [
        self::ROLE_PAYER,
        self::ROLE_PAYEE,
        self::ROLE_SCHOOL,
        self::ROLE_BANK,
        self::ROLE_VENDOR,
        self::ROLE_DONOR,
        self::ROLE_GOVERNMENT,
        self::ROLE_INTERNAL,
    ];

    protected $table = 'counterparties';

    protected $fillable = [
        'organization_id',
        'role',
        'code',
        'name',
        'npwp',
        'phone',
        'email',
        'address',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(CounterpartyAlias::class, 'counterparty_id');
    }
}
