<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->org = Organization::create([
            'code' => 'LP-MAARIF-CLP',
            'name' => 'LP Ma\'arif NU PCNU Cilacap',
            'type' => 'lp_maarif',
            'is_active' => true,
        ]);

        $this->user = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Admin Keuangan',
            'email' => 'admin@maarif-cilacap.org',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
        $this->user->assignRole(Role::ACCOUNTING_ADMIN);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@maarif-cilacap.org',
            'password' => 'password123',
            'device_name' => 'TestDevice',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'token',
            'token_type',
            'user' => [
                'id',
                'name',
                'email',
                'organization' => ['id', 'code', 'name'],
                'roles',
                'permissions',
            ],
        ]);

        $this->assertNotEmpty($response->json('token'));
        $this->assertEquals('admin@maarif-cilacap.org', $response->json('user.email'));
        $this->assertContains(Role::ACCOUNTING_ADMIN, $response->json('user.roles'));
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@maarif-cilacap.org',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_authenticated_user_can_fetch_profile(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
        $response->assertJson([
            'user' => [
                'id' => $this->user->id,
                'email' => 'admin@maarif-cilacap.org',
                'organization' => [
                    'id' => $this->org->id,
                    'code' => 'LP-MAARIF-CLP',
                ],
            ],
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    public function test_user_can_logout_successfully(): void
    {
        $token = $this->user->createToken('TestDevice')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Logout berhasil.',
        ]);

        $this->assertCount(0, $this->user->tokens);
    }
}
