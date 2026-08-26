<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->org = Organization::create([
            'code' => 'ORG-TEST',
            'name' => 'Organisasi Audit Test',
            'type' => 'lp_maarif',
            'is_active' => true,
        ]);

        $this->user = User::create([
            'organization_id' => $this->org->id,
            'name' => 'User Audit',
            'email' => 'audit.user@example.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
        $this->user->assignRole(Role::SUPER_ADMIN);
    }

    public function test_audit_service_creates_immutable_log_entry(): void
    {
        $log = AuditService::log(
            action: 'TEST_ACTION',
            modelType: Organization::class,
            modelId: $this->org->id,
            oldValues: ['name' => 'Old Name'],
            newValues: ['name' => 'New Name'],
            notes: 'Test audit entry',
            userId: $this->user->id,
            organizationId: $this->org->id
        );

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'action' => 'TEST_ACTION',
            'model_type' => Organization::class,
            'model_id' => $this->org->id,
            'notes' => 'Test audit entry',
        ]);
    }

    public function test_mutating_api_request_records_audit_log_and_correlation_id(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Correlation-ID', 'CORR-TEST-12345')
            ->postJson('/api/v1/organizations', [
                'code' => 'ORG-NEW',
                'name' => 'Organisasi Baru',
                'type' => 'lp_maarif',
            ]);

        $response->assertStatus(201);
        $response->assertHeader('X-Correlation-ID', 'CORR-TEST-12345');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'HTTP_POST',
            'user_id' => $this->user->id,
        ]);
    }
}
