<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

class JournalNumberAllocator
{
    /**
     * Atomically allocates the next sequential entry_number for an organization and fiscal year.
     * Uses PostgreSQL transaction-scoped advisory lock (pg_advisory_xact_lock) for absolute
     * concurrency protection without race conditions.
     */
    public function allocate(int $organizationId, int $fiscalYear): string
    {
        // 1. Acquire transaction-scoped advisory lock in PostgreSQL
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [$organizationId, $fiscalYear]);
        }

        // 2. Query maximum sequence within transaction
        $prefix = sprintf('JE-%04d-', $fiscalYear);

        $lastEntry = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('entry_number', 'LIKE', "{$prefix}%")
            ->orderByDesc('entry_number')
            ->lockForUpdate()
            ->first();

        $nextSeq = 1;
        if ($lastEntry) {
            $lastSeqStr = substr($lastEntry->entry_number, strlen($prefix));
            if (is_numeric($lastSeqStr)) {
                $nextSeq = ((int) $lastSeqStr) + 1;
            }
        }

        return sprintf('JE-%04d-%05d', $fiscalYear, $nextSeq);
    }
}
