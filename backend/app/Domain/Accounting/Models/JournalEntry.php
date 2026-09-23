<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Exceptions\AlreadyReversedException;
use App\Domain\Accounting\Exceptions\ImmutableJournalException;
use App\Domain\Organization\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class JournalEntry extends Model
{
    use HasFactory, BelongsToTenant;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_REVERSED = 'reversed';

    public const TYPE_OPENING_BALANCE = 'opening_balance';
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_RECEIPT = 'receipt';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_REVERSAL = 'reversal';

    protected $table = 'journal_entries';

    protected $fillable = [
        'organization_id',
        'fiscal_period_id',
        'entry_number',
        'entry_date',
        'entry_type',
        'reference',
        'description',
        'status',
        'posted_at',
        'posted_by',
        'reversed_entry_id',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date:Y-m-d',
        'posted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function (JournalEntry $entry) {
            if ($entry->isPosted() || $entry->isReversed()) {
                throw new ImmutableJournalException($entry->entry_number, 'delete');
            }
        });

        static::updating(function (JournalEntry $entry) {
            $originalStatus = $entry->getOriginal('status');

            if ($originalStatus === self::STATUS_POSTED) {
                // The ONLY allowed transition on POSTED journal is POSTED -> REVERSED with metadata
                $dirty = array_keys($entry->getDirty());
                $allowed = ['status', 'reversed_entry_id', 'updated_at'];
                $disallowed = array_diff($dirty, $allowed);

                if (!empty($disallowed) || $entry->status !== self::STATUS_REVERSED) {
                    throw new ImmutableJournalException($entry->entry_number, 'modify');
                }
            } elseif ($originalStatus === self::STATUS_REVERSED) {
                throw new ImmutableJournalException($entry->entry_number, 'modify');
            }
        });
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id')->orderBy('line_number');
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversed_entry_id');
    }

    public function markAsReversed(int $reversalEntryId): void
    {
        if (!$this->isPosted()) {
            throw new InvalidArgumentException("Only POSTED journals can be marked as reversed.");
        }

        if ($this->reversed_entry_id !== null) {
            throw new AlreadyReversedException($this->entry_number);
        }

        $this->status = self::STATUS_REVERSED;
        $this->reversed_entry_id = $reversalEntryId;
        $this->save();
    }
}
