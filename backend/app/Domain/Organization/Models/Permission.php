<?php

namespace App\Domain\Organization\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    // Canonical permissions
    public const ALL_PERMISSIONS = [
        // Organization & Users
        'organization.view',
        'organization.update',
        'user.view',
        'user.create',
        'user.update',
        'user.delete',
        'user.assign_role',

        // COA & Periods
        'coa.view',
        'coa.manage',
        'period.view',
        'period.manage',
        'period.close',
        'period.reopen',

        // Master Data
        'master_data.view',
        'master_data.manage',

        // Bank & Import
        'bank.view',
        'bank.manage',
        'bank.import',
        'bank.normalize',

        // Classification
        'classification.view',
        'classification.review',
        'classification.override',

        // Journal & Ledger
        'journal.view',
        'journal.create',
        'journal.review',
        'journal.approve',
        'journal.post',
        'journal.reverse',

        // Receivables
        'receivable.view',
        'receivable.create',
        'receivable.allocate',

        // Fund
        'fund.view',
        'fund.allocate',
        'fund.realize',
        'fund.return',

        // Reconciliation
        'reconciliation.view',
        'reconciliation.execute',
        'reconciliation.approve_exception',

        // Reports
        'report.view',
        'report.export',

        // Audit Trail
        'audit.view',
    ];
}
