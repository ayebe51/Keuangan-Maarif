<?php

namespace App\Domain\Classification\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Fund\Models\Fund;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassificationRule extends Model
{
    use BelongsToTenant, HasFactory;

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
        'version',
        'created_by',
        'approved_by',
        'approved_at',
        'effective_from',
        'effective_to',
        'max_auto_post_amount',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
        'auto_post' => 'boolean',
        'confidence' => 'float',
        'version' => 'integer',
        'approved_at' => 'datetime',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'max_auto_post_amount' => 'string',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function toSnapshot(): array
    {
        return [
            'rule_id' => $this->id,
            'organization_id' => $this->organization_id,
            'version' => (int) ($this->version ?? 1),
            'pattern' => $this->pattern,
            'pattern_type' => $this->pattern_type,
            'transaction_type' => $this->transaction_type,
            'target_account_id' => $this->target_account_id,
            'auto_post' => (bool) $this->auto_post,
            'max_auto_post_amount' => (string) ($this->max_auto_post_amount ?? '0.00'),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at ? $this->approved_at->format('Y-m-d H:i:s') : null,
        ];
    }

    /**
     * Evaluate whether this rule matches the given BankTransaction.
     */
    public function matches(BankTransaction $tx): bool
    {
        if (! $this->is_active) {
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
