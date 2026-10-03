<?php

namespace App\Domain\Fund\Models;

use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\ValueObjects\Money;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundAllocation extends Model
{
    use HasFactory, BelongsToTenant;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'fund_allocations';

    protected $fillable = [
        'organization_id',
        'fund_id',
        'fiscal_period_id',
        'description',
        'allocated_amount',
        'committed_amount',
        'disbursed_amount',
        'returned_amount',
        'status',
    ];

    protected $casts = [
        'allocated_amount' => 'string',
        'committed_amount' => 'string',
        'disbursed_amount' => 'string',
        'returned_amount' => 'string',
    ];

    protected $appends = [
