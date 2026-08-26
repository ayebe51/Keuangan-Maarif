<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;
    protected Organization $orgB;
    protected User $superAdmin;
    protected User $adminOrgA;

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

        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@admin.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole(Role::SUPER_ADMIN);

        $this->adminOrgA = User::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Admin Org A',
            'email' => 'admin@orga.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
        $this->adminOrgA->assignRole(Role::ACCOUNTING_ADMIN);
    }

    public function test_super_admin_can_view_all_organizations(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->getJson('/api/v1/organizations');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_tenant_admin_only_views_their_own_organization(): void
    {
        Sanctum::actingAs($this->adminOrgA);

        $response = $this->getJson('/api/v1/organizations');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals('ORG-A', $response->json('data.0.code'));
    }

    public function test_super_admin_can_create_new_organization(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson('/api/v1/organizations', [
            'code' => 'ORG-NEW',
            'name' => 'Organisasi Baru',
            'type' => 'pcnu',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('organizations', ['code' => 'ORG-NEW']);
    }

    public function test_non_super_admin_cannot_create_organization(): void
    {
        Sanctum::actingAs($this->adminOrgA);

        $response = $this->postJson('/api/v1/organizations', [
            'code' => 'ORG-UNAUTHORIZED',
            'name' => 'Organisasi Ilegal',
        ]);

        $response->assertStatus(403);
    }
}
