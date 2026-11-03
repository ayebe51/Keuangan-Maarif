<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntrySource extends Model
{
    use BelongsToTenant, HasFactory;

    public const ROLE_PRIMARY = 'primary';

    public const ROLE_SECONDARY = 'secondary';

    public const ROLE_TRANSFER_SOURCE = 'transfer_source';

    public const ROLE_TRANSFER_DESTINATION = 'transfer_destination';

    public const ROLE_REVERSAL_SOURCE = 'reversal_source';

    public const TYPE_BUSINESS_TRANSACTION = 'business_transaction';

    protected $table = 'journal_entry_sources';

    protected $fillable = [
        'organization_id',
        'journal_entry_id',
        'source_type',
        'source_id',
        'business_transaction_id',
        'leg_role',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function businessTransaction(): BelongsTo
    {
        return $this->belongsTo(BusinessTransaction::class, 'business_transaction_id');
    }
}
