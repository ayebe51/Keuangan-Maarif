<?php

namespace Tests\Feature;

use App\Domain\Accounting\Exceptions\NonPostableAccountException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Accounting\Services\AccountService;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CoaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;
    private Organization $orgB;
    private AccountService $accountService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\CoaSeeder::class);

        $this->orgA = Organization::where('code', 'LP-MAARIF-CLP')->first();
        $this->orgB = Organization::create([
            'code' => 'ORG-TEST-B',
            'name' => 'Organisasi Test B',
            'type' => 'madrasah',
            'is_active' => true,
        ]);

        $this->accountService = app(AccountService::class);
    }

    public function test_coa_hierarchy_and_tree_generation(): void
    {
        TenantContext::setTenantId($this->orgA->id);

        $tree = $this->accountService->getTree($this->orgA->id);

        $this->assertNotEmpty($tree);
        // Root node 1000 ASET
        $assetNode = collect($tree)->firstWhere('code', '1000');
        $this->assertNotNull($assetNode);
        $this->assertFalse($assetNode['is_postable']);
        $this->assertNotEmpty($assetNode['children']);

        // Kas dan Setara Kas 1100
        $kasNode = collect($assetNode['children'])->firstWhere('code', '1100');
        $this->assertNotNull($kasNode);
        $this->assertFalse($kasNode['is_postable']);

        // Leaf postable 1110 Kas Tunai
        $leafKas = collect($kasNode['children'])->firstWhere('code', '1110');
        $this->assertNotNull($leafKas);
        $this->assertTrue($leafKas['is_postable']);
    }

    public function test_ensure_postable_accepts_leaf_and_rejects_header(): void
    {
        TenantContext::setTenantId($this->orgA->id);

        // Header 1000 -> NonPostableAccountException (AT-003)
        $header1000 = Account::withoutGlobalScopes()->where('organization_id', $this->orgA->id)->where('code', '1000')->first();
        $this->expectException(NonPostableAccountException::class);
        $this->accountService->ensurePostable($header1000->id, $this->orgA->id);
    }

    public function test_ensure_postable_accepts_leaf_account(): void
    {
        TenantContext::setTenantId($this->orgA->id);

        $leaf1110 = Account::withoutGlobalScopes()->where('organization_id', $this->orgA->id)->where('code', '1110')->first();
        $account = $this->accountService->ensurePostable($leaf1110->id, $this->orgA->id);

        $this->assertSame('1110', $account->code);
    }

    public function test_cross_tenant_account_access_rejected(): void
    {
        // Try accessing Org A account while acting as Org B
        $leafA = Account::withoutGlobalScopes()->where('organization_id', $this->orgA->id)->where('code', '1110')->first();

        $this->expectException(CrossTenantViolationException::class);
        $this->accountService->ensurePostable($leafA->id, $this->orgB->id);
    }

    public function test_parent_cycle_protection_rejects_self_as_parent(): void
    {
        TenantContext::setTenantId($this->orgA->id);

        $acc = Account::withoutGlobalScopes()->where('organization_id', $this->orgA->id)->where('code', '1110')->first();

        $this->expectException(InvalidArgumentException::class);
        $this->accountService->updateAccount($acc, ['parent_id' => $acc->id]);
    }

    public function test_parent_cycle_protection_rejects_descendant_as_parent(): void
    {
        TenantContext::setTenantId($this->orgA->id);

        $a1000 = Account::withoutGlobalScopes()->where('organization_id', $this->orgA->id)->where('code', '1000')->first();
        $a1110 = Account::withoutGlobalScopes()->where('organization_id', $this->orgA->id)->where('code', '1110')->first();

        // 1110 is descendant of 1000. Trying to set 1000's parent to 1110 must fail
        $this->expectException(InvalidArgumentException::class);
        $this->accountService->updateAccount($a1000, ['parent_id' => $a1110->id]);
    }

    public function test_bank_account_mapping_resolves_correctly(): void
    {
        // AT-014: Bank account maps to COA leaf
        $mapping = AccountMapping::withoutGlobalScopes()
            ->where('organization_id', $this->orgA->id)
            ->where('mapping_type', AccountMapping::TYPE_BANK_ACCOUNT)
            ->first();

        $this->assertNotNull($mapping);
        $resolved = $this->accountService->resolveAccount($this->orgA->id, AccountMapping::TYPE_BANK_ACCOUNT, $mapping->mapping_key);

        $this->assertNotNull($resolved);
        $this->assertSame($mapping->account_id, $resolved->id);
        $this->assertTrue($resolved->is_postable);
    }
}
