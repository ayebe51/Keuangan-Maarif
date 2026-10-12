<?php

namespace App\Domain\Bank\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BankRawSource extends Model
{
    use HasFactory, BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_NORMALIZED = 'normalized';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_ERROR = 'error';

    protected $table = 'bank_raw_sources';

    protected $fillable = [
        'organization_id',
        'bank_import_id',
        'bank_account_id',
        'source_file',
        'source_sheet',
        'source_row',
        'raw_data',
        'row_fingerprint',
        'status',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'source_row' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function bankImport(): BelongsTo
    {
        return $this->belongsTo(BankImport::class, 'bank_import_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function bankTransaction(): HasOne
    {
        return $this->hasOne(BankTransaction::class, 'raw_source_id');
    }

    public function isNormalized(): bool
    {
        return $this->status === self::STATUS_NORMALIZED;
    }

    public function isDuplicate(): bool
    {
        return $this->status === self::STATUS_DUPLICATE;
    }

    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }
}
