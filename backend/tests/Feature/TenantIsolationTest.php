<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\Attachment;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;
    protected Organization $orgB;
    protected User $userA;
    protected User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::reset();

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

        $this->userA = User::create([
            'organization_id' => $this->orgA->id,
            'name' => 'User Org A',
            'email' => 'usera@orga.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->userB = User::create([
            'organization_id' => $this->orgB->id,
            'name' => 'User Org B',
            'email' => 'userb@orgb.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
    }

    public function test_tenant_context_filters_models_with_belongs_to_tenant(): void
    {
        // Create attachment in Org A
        TenantContext::forTenant($this->orgA->id, function () {
            Attachment::create([
                'organization_id' => $this->orgA->id,
                'attachable_type' => 'App\Models\User',
                'attachable_id' => $this->userA->id,
                'filename' => 'doc_a.pdf',
                'original_name' => 'doc_a.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 1024,
                'disk' => 'local',
                'path' => '/uploads/doc_a.pdf',
                'uploaded_by' => $this->userA->id,
            ]);
        });

        // Create attachment in Org B
        TenantContext::forTenant($this->orgB->id, function () {
            Attachment::create([
                'organization_id' => $this->orgB->id,
                'attachable_type' => 'App\Models\User',
                'attachable_id' => $this->userB->id,
                'filename' => 'doc_b.pdf',
                'original_name' => 'doc_b.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 2048,
                'disk' => 'local',
                'path' => '/uploads/doc_b.pdf',
                'uploaded_by' => $this->userB->id,
            ]);
        });

        // In Org A context, only Org A's attachment is visible
        TenantContext::forTenant($this->orgA->id, function () {
            $attachments = Attachment::all();
            $this->assertCount(1, $attachments);
            $this->assertEquals('doc_a.pdf', $attachments->first()->filename);
        });

        // In Org B context, only Org B's attachment is visible
        TenantContext::forTenant($this->orgB->id, function () {
            $attachments = Attachment::all();
            $this->assertCount(1, $attachments);
            $this->assertEquals('doc_b.pdf', $attachments->first()->filename);
        });

        // Without tenant context (bypassed), all attachments are visible
        TenantContext::withoutTenant(function () {
            $attachments = Attachment::all();
            $this->assertCount(2, $attachments);
        });
    }

    public function test_cross_tenant_creation_is_rejected(): void
    {
        $this->expectException(CrossTenantViolationException::class);

        // While in Org A context, attempting to write Org B's ID directly must throw exception
        TenantContext::forTenant($this->orgA->id, function () {
            Attachment::create([
                'organization_id' => $this->orgB->id, // Mismatch!
                'attachable_type' => 'App\Models\User',
                'attachable_id' => $this->userA->id,
                'filename' => 'illegal.pdf',
                'original_name' => 'illegal.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 1024,
                'disk' => 'local',
                'path' => '/uploads/illegal.pdf',
                'uploaded_by' => $this->userA->id,
            ]);
        });
    }

    public function test_regular_user_cannot_access_other_organization_via_header(): void
    {
        Sanctum::actingAs($this->userA);

        // User A trying to access API by passing X-Organization-ID of Org B
        $response = $this->withHeader('X-Organization-ID', (string)$this->orgB->id)
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'CROSS_TENANT_VIOLATION',
        ]);
    }
}
