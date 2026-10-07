<?php

namespace App\Domain\Bank\Models;

use App\Domain\Bank\Enums\BankImportStatus;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\User;
use App\Domain\Organization\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankImport extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'bank_imports';

    protected $fillable = [
        'organization_id',
        'bank_account_id',
        'imported_by',
        'filename',
        'file_hash',
        'file_path',
        'format',
        'mapping_version',
        'total_rows',
        'imported_rows',
        'skipped_rows',
        'error_rows',
        'status',
        'error_log',
        'has_discrepancies',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'status' => BankImportStatus::class,
        'has_discrepancies' => 'boolean',
        'total_rows' => 'integer',
        'imported_rows' => 'integer',
        'skipped_rows' => 'integer',
        'error_rows' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function rawSources(): HasMany
    {
        return $this->hasMany(BankRawSource::class, 'bank_import_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'bank_import_id');
    }
}
