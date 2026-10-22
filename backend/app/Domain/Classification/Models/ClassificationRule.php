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
        'confidence' => 'float',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function transactionCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    public function targetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'target_account_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class, 'counterparty_id');
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class, 'fund_id');
    }

    /**
     * Evaluate whether this rule matches the given BankTransaction.
     */
    public function matches(BankTransaction $tx): bool
    {
        if (!$this->is_active) {
            return false;
        }

        // Bank account filter
        if ($this->bank_account_id !== null && (int) $this->bank_account_id !== (int) $tx->bank_account_id) {
            return false;
        }

        // Direction filter
        if ($this->direction !== null) {
            $txDir = is_object($tx->direction) ? $tx->direction->value : (string) $tx->direction;
            if (strtoupper($this->direction) !== strtoupper($txDir)) {
                return false;
            }
        }

        // Amount filters
        $amount = (float) $tx->amount;
        if ($this->min_amount !== null && $amount < (float) $this->min_amount) {
            return false;
        }
        if ($this->max_amount !== null && $amount > (float) $this->max_amount) {
            return false;
        }

        // Pattern matching on description or raw counterparty name
        $desc = (string) ($tx->description ?? '');
        $pattern = trim($this->pattern);

        if ($pattern === '') {
            return true;
        }

        $type = strtolower($this->pattern_type ?? 'contains');

        return match ($type) {
            'exact' => mb_strtolower(trim($desc)) === mb_strtolower($pattern),
            'starts_with' => mb_stripos(trim($desc), $pattern) === 0,
            'ends_with' => str_ends_with(mb_strtolower(trim($desc)), mb_strtolower($pattern)),
            'regex' => (bool) @preg_match($pattern, $desc),
            default => mb_stripos($desc, $pattern) !== false,
        };
    }
}
