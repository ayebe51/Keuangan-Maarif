<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Organization\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountMapping extends Model
{
    use HasFactory, BelongsToTenant;

    public const TYPE_BANK_ACCOUNT = 'bank_account';
    public const TYPE_TRANSACTION_TYPE = 'transaction_type';
    public const TYPE_REPORT_CATEGORY = 'report_category';
    public const TYPE_LEGACY_CODE = 'legacy_code';

    protected $table = 'account_mappings';

    protected $fillable = [
        'organization_id',
        'account_id',
        'mapping_type',
        'mapping_key',
        'mapping_value',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
