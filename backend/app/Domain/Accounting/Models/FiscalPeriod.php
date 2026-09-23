<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Organization\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalPeriod extends Model
{
    use HasFactory, BelongsToTenant;

    public const STATUS_OPEN = 'open';
    public const STATUS_SOFT_CLOSE = 'soft_close';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_LOCKED = 'locked';

    protected $table = 'fiscal_periods';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'period_type',
        'start_date',
        'end_date',
        'status',
        'is_current',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'is_current' => 'boolean',
        'closed_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isSoftClose(): bool
    {
        return $this->status === self::STATUS_SOFT_CLOSE;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_CLOSED, self::STATUS_LOCKED], true);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where('start_date', '<=', $date)
                     ->where('end_date', '>=', $date);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
