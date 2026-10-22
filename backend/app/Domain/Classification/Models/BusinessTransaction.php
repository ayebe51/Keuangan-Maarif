<?php

namespace App\Domain\Classification\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Fund\Models\Fund;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessTransaction extends Model
{
    use HasFactory, BelongsToTenant;

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
    ];

    protected $casts = [
        'transaction_date' => 'date:Y-m-d',
        'value_date' => 'date:Y-m-d',
        'classification_status' => ClassificationStatus::class,
        'classification_confidence' => 'float',
        'is_period_locked' => 'boolean',
        'auto_post_eligible' => 'boolean',
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

        return $prefix . str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT);
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

    public function isPosted(): bool
    {
        return strtolower($this->status) === 'posted';
    }
}
