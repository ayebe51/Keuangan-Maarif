<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Permission;
use App\Domain\Organization\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_all_seven_canonical_roles_exist(): void
    {
        $roles = [
            Role::SUPER_ADMIN,
            Role::ACCOUNTING_ADMIN,
            Role::ACCOUNTING_OPERATOR,
            Role::RECONCILER,
            Role::APPROVER,
            Role::REPORT_VIEWER,
            Role::AUDITOR,
        ];

        foreach ($roles as $roleName) {
            $this->assertDatabaseHas('roles', [
                'name' => $roleName,
                'guard_name' => 'sanctum',
            ]);
        }
    }

    public function test_accounting_operator_segregation_of_duties(): void
    {
        $operator = User::create([
            'name' => 'Operator Test',
            'email' => 'operator.test@example.com',
            'password' => bcrypt('password123'),
        ]);
        $operator->assignRole(Role::ACCOUNTING_OPERATOR);

        // Operator CAN create draft journals
        $this->assertTrue($operator->hasPermissionTo('journal.create'));
        $this->assertTrue($operator->hasPermissionTo('bank.import'));

        // Operator CANNOT approve or post journals (Segregation of Duties)
        $this->assertFalse($operator->hasPermissionTo('journal.approve'));
        $this->assertFalse($operator->hasPermissionTo('journal.post'));
        $this->assertFalse($operator->hasPermissionTo('period.close'));
    }

    public function test_approver_has_approval_permissions_only(): void
    {
        $approver = User::create([
            'name' => 'Approver Test',
            'email' => 'approver.test@example.com',
            'password' => bcrypt('password123'),
        ]);
        $approver->assignRole(Role::APPROVER);

        $this->assertTrue($approver->hasPermissionTo('journal.approve'));
        $this->assertTrue($approver->hasPermissionTo('journal.post'));
        $this->assertTrue($approver->hasPermissionTo('reconciliation.approve_exception'));

        // Approver does not directly create transactions in routine workflow
        $this->assertFalse($approver->hasPermissionTo('bank.import'));
    }

    public function test_auditor_has_read_only_audit_permissions(): void
    {
        $auditor = User::create([
            'name' => 'Auditor Test',
            'email' => 'auditor.test@example.com',
            'password' => bcrypt('password123'),
        ]);
        $auditor->assignRole(Role::AUDITOR);

        $this->assertTrue($auditor->hasPermissionTo('audit.view'));
        $this->assertTrue($auditor->hasPermissionTo('report.view'));
        $this->assertTrue($auditor->hasPermissionTo('journal.view'));

        $this->assertFalse($auditor->hasPermissionTo('journal.create'));
        $this->assertFalse($auditor->hasPermissionTo('journal.approve'));
        $this->assertFalse($auditor->hasPermissionTo('period.close'));
    }
}
