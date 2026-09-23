<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Organization\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasFactory, BelongsToTenant;

    public const TYPE_ASSET = 'asset';
    public const TYPE_LIABILITY = 'liability';
    public const TYPE_EQUITY = 'equity';
    public const TYPE_REVENUE = 'revenue';
    public const TYPE_EXPENSE = 'expense';

    public const BALANCE_DEBIT = 'debit';
    public const BALANCE_CREDIT = 'credit';

    protected $table = 'accounts';

    protected $fillable = [
        'organization_id',
        'parent_id',
        'account_type',
        'normal_balance',
        'code',
        'name',
        'description',
        'is_postable',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_postable' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id')->orderBy('sort_order')->orderBy('code');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(AccountMapping::class, 'account_id');
    }

    public function isLeaf(): bool
    {
        return $this->is_postable && $this->children()->count() === 0;
    }
}
