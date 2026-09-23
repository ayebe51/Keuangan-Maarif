<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\Account;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $admin;
    private User $operator;
    private Account $accKas;
    private Account $accBeban;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\CoaSeeder::class);

        $this->org = Organization::where('code', 'LP-MAARIF-CLP')->first();
        TenantContext::setTenantId($this->org->id);

        $this->admin = User::where('email', 'admin@maarif-cilacap.org')->first();
        $this->operator = User::where('email', 'operator@maarif-cilacap.org')->first();

        $this->accKas = Account::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', '1110')->first();
        $this->accBeban = Account::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', '5100')->first();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/accounts');
        $response->assertStatus(401);
    }

    public function test_get_accounts_tree(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/accounts/tree', [
            'X-Tenant-ID' => (string) $this->org->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_create_and_post_journal_via_api(): void
    {
        // 1. Operator creates draft
        Sanctum::actingAs($this->operator);

        $createResponse = $this->postJson('/api/v1/journal-entries', [
            'entry_date' => '2026-03-15',
            'description' => 'API Journal Test Transaction',
            'lines' => [
                ['account_id' => $this->accBeban->id, 'debit' => 500000, 'credit' => 0],
                ['account_id' => $this->accKas->id, 'debit' => 0, 'credit' => 500000],
            ],
        ], [
            'X-Tenant-ID' => (string) $this->org->id,
        ]);

        $createResponse->assertStatus(201);
        $journalId = $createResponse->json('data.id');
        $this->assertNotNull($journalId);
        $this->assertSame('draft', $createResponse->json('data.status'));

        // 2. Operator tries to post own journal -> SoD 403
        $selfPostResponse = $this->postJson("/api/v1/journal-entries/{$journalId}/post", [], [
            'X-Tenant-ID' => (string) $this->org->id,
        ]);
        $selfPostResponse->assertStatus(403);

        // 3. Admin posts the journal -> Success 200
        Sanctum::actingAs($this->admin);

        $adminPostResponse = $this->postJson("/api/v1/journal-entries/{$journalId}/post", [], [
            'X-Tenant-ID' => (string) $this->org->id,
        ]);
        $adminPostResponse->assertStatus(200);
        $this->assertSame('posted', $adminPostResponse->json('data.status'));

        // 4. Reverse the posted journal -> Success 200
        $reverseResponse = $this->postJson("/api/v1/journal-entries/{$journalId}/reverse", [
            'reason' => 'Koreksi kesalahan pencatatan via API',
        ], [
            'X-Tenant-ID' => (string) $this->org->id,
        ]);
        $reverseResponse->assertStatus(200);
        $this->assertSame('reversal', $reverseResponse->json('data.entry_type'));
    }
}
