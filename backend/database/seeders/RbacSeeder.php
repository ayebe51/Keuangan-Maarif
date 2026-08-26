<?php

namespace Database\Seeders;

use App\Domain\Organization\Models\Permission;
use App\Domain\Organization\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Create all canonical permissions
        foreach (Permission::ALL_PERMISSIONS as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'sanctum',
            ]);
        }

        // 2. Create canonical roles and assign permissions

        // SUPER_ADMIN
        $superAdmin = Role::firstOrCreate([
            'name' => Role::SUPER_ADMIN,
            'guard_name' => 'sanctum',
            'organization_id' => null,
        ]);
        $superAdmin->syncPermissions(Permission::where('guard_name', 'sanctum')->get());

        // ACCOUNTING_ADMIN
        $accountingAdmin = Role::firstOrCreate([
            'name' => Role::ACCOUNTING_ADMIN,
            'guard_name' => 'sanctum',
            'organization_id' => null,
        ]);
        $accountingAdmin->syncPermissions([
            'organization.view',
            'user.view', 'user.create', 'user.update', 'user.assign_role',
            'coa.view', 'coa.manage',
            'period.view', 'period.manage', 'period.close', 'period.reopen',
            'master_data.view', 'master_data.manage',
            'bank.view', 'bank.manage', 'bank.import', 'bank.normalize',
            'classification.view', 'classification.review', 'classification.override',
            'journal.view', 'journal.create', 'journal.review', 'journal.approve', 'journal.post', 'journal.reverse',
            'receivable.view', 'receivable.create', 'receivable.allocate',
            'fund.view', 'fund.allocate', 'fund.realize', 'fund.return',
            'reconciliation.view', 'reconciliation.execute', 'reconciliation.approve_exception',
            'report.view', 'report.export',
            'audit.view',
        ]);

        // ACCOUNTING_OPERATOR (Creator: No approve, no post, no reverse, no close period)
        $accountingOperator = Role::firstOrCreate([
            'name' => Role::ACCOUNTING_OPERATOR,
            'guard_name' => 'sanctum',
            'organization_id' => null,
        ]);
        $accountingOperator->syncPermissions([
            'coa.view',
            'period.view',
            'master_data.view', 'master_data.manage',
            'bank.view', 'bank.import', 'bank.normalize',
            'classification.view', 'classification.review',
            'journal.view', 'journal.create', 'journal.review',
            'receivable.view', 'receivable.create', 'receivable.allocate',
            'fund.view', 'fund.allocate', 'fund.realize',
            'reconciliation.view',
            'report.view',
        ]);

        // RECONCILER
        $reconciler = Role::firstOrCreate([
            'name' => Role::RECONCILER,
            'guard_name' => 'sanctum',
            'organization_id' => null,
        ]);
        $reconciler->syncPermissions([
            'bank.view',
            'journal.view',
            'reconciliation.view', 'reconciliation.execute',
            'report.view',
        ]);

        // APPROVER (Approver: approve & post journals, approve exceptions)
        $approver = Role::firstOrCreate([
            'name' => Role::APPROVER,
            'guard_name' => 'sanctum',
            'organization_id' => null,
        ]);
        $approver->syncPermissions([
            'journal.view', 'journal.review', 'journal.approve', 'journal.post',
            'reconciliation.view', 'reconciliation.approve_exception',
            'report.view',
        ]);

        // REPORT_VIEWER
        $reportViewer = Role::firstOrCreate([
            'name' => Role::REPORT_VIEWER,
            'guard_name' => 'sanctum',
            'organization_id' => null,
        ]);
        $reportViewer->syncPermissions([
            'coa.view',
            'journal.view',
            'report.view', 'report.export',
        ]);

        // AUDITOR
        $auditor = Role::firstOrCreate([
            'name' => Role::AUDITOR,
            'guard_name' => 'sanctum',
            'organization_id' => null,
        ]);
        $auditor->syncPermissions([
            'coa.view',
            'journal.view',
            'reconciliation.view',
            'report.view', 'report.export',
            'audit.view',
        ]);
    }
}
