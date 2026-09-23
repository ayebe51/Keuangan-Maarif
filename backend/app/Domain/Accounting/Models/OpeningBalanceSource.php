<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Exceptions\ImmutableJournalException;
use App\Domain\Organization\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningBalanceSource extends Model
{
    use HasFactory, BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_POSTED = 'posted';
    public const STATUS_ERROR = 'error';

    public const DIRECTION_DEBIT = 'debit';
    public const DIRECTION_CREDIT = 'credit';

    protected $table = 'opening_balance_sources';

    protected $fillable = [
        'organization_id',
        'fiscal_period_id',
        'bank_account_id',
        'effective_date',
        'amount',
        'direction',
        'description',
        'status',
        'journal_entry_id',
        'is_fixture',
        'fixture_note',
        'created_by',
    ];

    protected $casts = [
        'effective_date' => 'date:Y-m-d',
        'amount' => 'string',
        'is_fixture' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(function (OpeningBalanceSource $source) {
            if ($source->getOriginal('status') === self::STATUS_POSTED) {
                throw new ImmutableJournalException(
                    "OBS-{$source->id}",
                    'modify',
                    "Opening balance source 'OBS-{$source->id}' is already posted and immutable."
                );
            }
        });

        static::deleting(function (OpeningBalanceSource $source) {
            if ($source->isPosted()) {
                throw new ImmutableJournalException(
                    "OBS-{$source->id}",
                    'delete',
                    "Cannot delete opening balance source 'OBS-{$source->id}' because it has already been posted."
                );
            }
        });
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED || $this->journal_entry_id !== null;
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
