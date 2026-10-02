<?php

namespace App\Domain\Counterparty\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CounterpartyAlias extends Model
{
    use HasFactory, BelongsToTenant;

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_BANK_IMPORT = 'bank_import';
    public const SOURCE_SYSTEM = 'system';

    protected $table = 'counterparty_aliases';

    protected $fillable = [
        'organization_id',
        'counterparty_id',
        'alias_name',
        'source',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function normalize(string $name): string
    {
        // Strip duplicate spaces, trim, uppercase
        return mb_strtoupper(preg_replace('/\s+/', ' ', trim($name)));
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class, 'counterparty_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
