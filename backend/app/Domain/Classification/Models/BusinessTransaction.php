<?php

namespace App\Domain\Classification\Models;

use App\Domain\Accounting\Enums\TransferPairingStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntrySource;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Fund\Models\Fund;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessTransaction extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'business_transactions';

    protected $fillable = [
        'organization_id',
        'transaction_number',
        'bank_transaction_id',
        'fiscal_period_id',
        'transaction_date',
        'value_date',
        'transaction_type',
        'direction',
        'amount',
        'counterparty_id',
        'target_account_id',
        'transaction_category_id',
        'fund_id',
        'paired_business_transaction_id',
        'classification_status',
        'classification_method',
        'classification_confidence',
        'is_period_locked',
        'auto_post_eligible',
        'description',
        'notes',
        'status',
        'posted_journal_entry_id',
        'transfer_pairing_status',
        'rule_snapshot',
        'posted_at',
        'posted_by',
        'posting_error',
    ];

    protected $casts = [
        'transaction_date' => 'date:Y-m-d',
        'value_date' => 'date:Y-m-d',
        'classification_status' => ClassificationStatus::class,
        'classification_confidence' => 'float',
        'is_period_locked' => 'boolean',
        'auto_post_eligible' => 'boolean',
        'rule_snapshot' => 'array',
        'posted_at' => 'datetime',
        'posting_error' => 'array',
    ];

    protected function amount(): Attribute
    {
        return Attribute::make(
            get: fn ($val) => number_format((float) ($val ?? 0), 2, '.', ''),
        );
    }

    public static function generateTransactionNumber(int $organizationId, int $year): string
    {
        $prefix = "BT-{$year}-";
        $last = self::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('transaction_number', 'LIKE', "{$prefix}%")
            ->orderByDesc('id')
            ->value('transaction_number');

        $nextSeq = 1;
        if ($last) {
            $numPart = substr($last, strlen($prefix));
            $nextSeq = ((int) $numPart) + 1;
        }

        return $prefix.str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class, 'counterparty_id');
    }

    public function targetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'target_account_id');
    }

    public function transactionCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class, 'fund_id');
    }

    public function pairedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'paired_business_transaction_id');
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(ClassificationSuggestion::class, 'business_transaction_id');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'business_transaction_id');
    }

    public function journalEntrySources(): HasMany
    {
        return $this->hasMany(JournalEntrySource::class, 'business_transaction_id');
    }

    public function postedJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'posted_journal_entry_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isPosted(): bool
    {
        return strtolower((string) $this->status) === 'posted';
    }

    public function isRejected(): bool
    {
        return strtolower((string) $this->status) === 'rejected';
    }

    public function isReversed(): bool
    {
        return strtolower((string) $this->status) === 'reversed';
    }

    public function isTransfer(): bool
    {
        return $this->transaction_type === 'BANK_TRANSFER';
    }

    public function isTransferPaired(): bool
    {
        return $this->transfer_pairing_status === TransferPairingStatus::PAIRED->value;
    }

    public function isTransferAmbiguous(): bool
    {
        return $this->transfer_pairing_status === TransferPairingStatus::PAIRING_AMBIGUOUS->value;
    }
}
