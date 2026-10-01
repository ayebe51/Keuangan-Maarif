<?php

namespace App\Domain\Classification\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransactionCategory extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes;

    public const DIRECTION_IN = 'IN';
    public const DIRECTION_OUT = 'OUT';
    public const DIRECTION_TRANSFER = 'TRANSFER';

    public const ALL_DIRECTIONS = [
        self::DIRECTION_IN,
        self::DIRECTION_OUT,
        self::DIRECTION_TRANSFER,
    ];

    protected $table = 'transaction_categories';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'direction',
        'default_debit_account_id',
        'default_credit_account_id',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function defaultDebitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_debit_account_id');
    }

    public function defaultCreditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_credit_account_id');
    }
}
