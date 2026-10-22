<?php

namespace App\Domain\Classification\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Fund\Models\Fund;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassificationRule extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'classification_rules';

    protected $fillable = [
        'organization_id',
        'name',
        'priority',
        'is_active',
        'rule_type',
        'direction',
        'bank_account_id',
        'pattern',
        'pattern_type',
        'min_amount',
        'max_amount',
        'transaction_type',
        'transaction_category_id',
        'target_account_id',
        'counterparty_id',
        'fund_id',
        'auto_post',
        'confidence',
        'description',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
        'auto_post' => 'boolean',
