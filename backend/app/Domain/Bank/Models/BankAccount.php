<?php

namespace App\Domain\Bank\Models;

use App\Domain\Accounting\Exceptions\OpeningBalanceConfigurationException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\OpeningBalanceSource;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes;

    public const TYPE_GIRO = 'giro';
    public const TYPE_SAVINGS = 'savings';
    public const TYPE_CURRENT = 'current';
    public const TYPE_CASH = 'cash';

    protected $table = 'bank_accounts';

    protected $fillable = [
        'organization_id',
        'bank_name',
        'account_number',
        'account_name',
        'branch',
        'currency',
        'account_id',
        'type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // INVARIANT GUARD: Prevent opening_balance from ever being stored on bank_accounts
        static::saving(function (BankAccount $bankAccount) {
            if ($bankAccount->isDirty('opening_balance') || isset($bankAccount->getAttributes()['opening_balance'])) {
                throw new OpeningBalanceConfigurationException(
                    "Opening balance cannot be set directly on BankAccount. Opening balance MUST flow through OpeningBalanceSource -> JournalEntry -> JournalLine."
                );
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function coaAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function openingBalanceSources(): HasMany
    {
        return $this->hasMany(OpeningBalanceSource::class, 'bank_account_id');
    }
}
