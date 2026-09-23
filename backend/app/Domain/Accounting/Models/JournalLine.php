<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Exceptions\ImmutableJournalException;
use App\Domain\Organization\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'journal_lines';

    protected $fillable = [
        'organization_id',
        'journal_entry_id',
        'line_number',
        'account_id',
        'debit',
        'credit',
        'description',
        'bank_account_id',
        'counterparty_id',
        'fund_id',
        'receivable_id',
    ];

    protected $casts = [
        'line_number' => 'integer',
    ];

    protected function debit(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($val) => number_format((float) ($val ?? 0), 2, '.', ''),
        );
    }

    protected function credit(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($val) => number_format((float) ($val ?? 0), 2, '.', ''),
        );
    }

    protected static function booted(): void
    {
        $guardPosted = function (JournalLine $line, string $action) {
            $entry = $line->journalEntry ?: JournalEntry::withoutGlobalScopes()->find($line->journal_entry_id);
            if ($entry && ($entry->isPosted() || $entry->isReversed())) {
                throw new ImmutableJournalException($entry->entry_number, "{$action} lines of");
            }
        };

        static::creating(function (JournalLine $line) use ($guardPosted) {
            $guardPosted($line, 'add');
        });

        static::updating(function (JournalLine $line) use ($guardPosted) {
            $guardPosted($line, 'modify');
        });

        static::deleting(function (JournalLine $line) use ($guardPosted) {
            $guardPosted($line, 'delete');
        });
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
