<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;
    protected Organization $orgB;
    protected User $adminOrgA;
    protected User $operatorOrgA;
    protected User $adminOrgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->orgA = Organization::create([
            'code' => 'ORG-A',
            'name' => 'Organisasi A',
            'type' => 'lp_maarif',
            'is_active' => true,
        ]);

        $this->orgB = Organization::create([
            'code' => 'ORG-B',
            'name' => 'Organisasi B',
            'type' => 'lp_maarif',
            'is_active' => true,
        ]);

        $this->adminOrgA = User::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Admin A',
            'email' => 'admin@orga.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
        $this->adminOrgA->assignRole(Role::ACCOUNTING_ADMIN);

        $this->operatorOrgA = User::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Operator A',
            'email' => 'operator@orga.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
        $this->operatorOrgA->assignRole(Role::ACCOUNTING_OPERATOR);

        $this->adminOrgB = User::create([
            'organization_id' => $this->orgB->id,
            'name' => 'Admin B',
            'email' => 'admin@orgb.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
        $this->adminOrgB->assignRole(Role::ACCOUNTING_ADMIN);
    }

    public function test_tenant_admin_only_sees_users_in_same_organization(): void
    {
        Sanctum::actingAs($this->adminOrgA);

        $response = $this->getJson('/api/v1/users');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $emails = collect($response->json('data'))->pluck('email')->all();
        $this->assertContains('admin@orga.com', $emails);
        $this->assertContains('operator@orga.com', $emails);
        $this->assertNotContains('admin@orgb.com', $emails);
    }

    public function test_tenant_admin_can_create_user_in_own_organization(): void
    {
        Sanctum::actingAs($this->adminOrgA);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Staff',
            'email' => 'staff@orga.com',
            'password' => 'secret12345',
            'roles' => [Role::ACCOUNTING_OPERATOR],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'staff@orga.com',
            'organization_id' => $this->orgA->id,
        ]);
    }

    public function test_tenant_admin_cannot_access_or_modify_other_organization_user(): void
    {
        Sanctum::actingAs($this->adminOrgA);

        // View user in Org B
        $response = $this->getJson('/api/v1/users/' . $this->adminOrgB->id);
        $response->assertStatus(403);

        // Delete user in Org B
        $deleteResponse = $this->deleteJson('/api/v1/users/' . $this->adminOrgB->id);
        $deleteResponse->assertStatus(403);
    }
}
