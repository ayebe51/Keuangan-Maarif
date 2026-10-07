<?php

namespace App\Domain\Bank\Models;

use App\Domain\Bank\Enums\BankTransactionDirection;
use App\Domain\Bank\Enums\BankTransactionStatus;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransaction extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'bank_transactions';

    protected $fillable = [
        'organization_id',
        'bank_account_id',
        'bank_import_id',
        'raw_source_id',
        'row_sequence',
        'transaction_date',
        'value_date',
        'direction',
        'amount',
        'balance_after',
        'description',
        'reference_number',
        'raw_counterparty_name',
        'counterparty_id',
        'fingerprint',
        'status',
        'notes',
    ];

    protected $casts = [
        'direction' => BankTransactionDirection::class,
        'status' => BankTransactionStatus::class,
        'transaction_date' => 'date:Y-m-d',
        'value_date' => 'date:Y-m-d',
        'row_sequence' => 'integer',
    ];

    protected function amount(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($val) => number_format((float) ($val ?? 0), 2, '.', ''),
        );
    }

    protected function balanceAfter(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($val) => $val !== null ? number_format((float) $val, 2, '.', '') : null,
        );
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function bankImport(): BelongsTo
    {
        return $this->belongsTo(BankImport::class, 'bank_import_id');
    }

    public function rawSource(): BelongsTo
    {
        return $this->belongsTo(BankRawSource::class, 'raw_source_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class, 'counterparty_id');
    }

    public function isCashIn(): bool
    {
        return $this->direction === BankTransactionDirection::IN;
    }

    public function isCashOut(): bool
    {
        return $this->direction === BankTransactionDirection::OUT;
    }
}
