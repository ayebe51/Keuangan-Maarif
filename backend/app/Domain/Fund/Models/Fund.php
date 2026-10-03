<?php

namespace App\Domain\Fund\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fund extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes;

    public const TYPE_RESTRICTED = 'restricted';
    public const TYPE_UNRESTRICTED = 'unrestricted';
    public const TYPE_TEMPORARILY_RESTRICTED = 'temporarily_restricted';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const ALL_TYPES = [
        self::TYPE_RESTRICTED,
        self::TYPE_UNRESTRICTED,
        self::TYPE_TEMPORARILY_RESTRICTED,
    ];

    public const ALL_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'funds';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'fund_type',
        'account_id',
        'start_date',
        'end_date',
        'budget_amount',
        'description',
        'status',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'budget_amount' => 'string',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(FundAllocation::class, 'fund_id');
    }
}
