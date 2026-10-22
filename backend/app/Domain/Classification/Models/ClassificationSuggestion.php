<?php

namespace App\Domain\Classification\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassificationSuggestion extends Model
{
    use HasFactory, BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    protected $table = 'classification_suggestions';

    protected $fillable = [
        'organization_id',
        'bank_transaction_id',
        'business_transaction_id',
        'suggested_type',
        'suggested_account_id',
        'suggested_category_id',
        'suggested_counterparty_id',
        'confidence',
        'evidence',
        'explanation',
        'metadata',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'confidence' => 'float',
        'metadata' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    public function businessTransaction(): BelongsTo
    {
        return $this->belongsTo(BusinessTransaction::class, 'business_transaction_id');
    }

    public function suggestedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'suggested_account_id');
    }

    public function suggestedCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'suggested_category_id');
    }

    public function suggestedCounterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class, 'suggested_counterparty_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
