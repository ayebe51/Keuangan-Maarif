<?php

namespace Database\Seeders;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Classification\Models\TransactionCategory;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Counterparty\Models\CounterpartyAlias;
use App\Domain\Fund\Models\Fund;
use App\Domain\Organization\Models\Organization;
use App\Domain\Program\Models\Program;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $organizations = Organization::all();

        foreach ($organizations as $org) {
            $this->seedMasterDataForOrganization($org);
        }
    }

    public function seedMasterDataForOrganization(Organization $org): void
    {
        DB::transaction(function () use ($org) {
            $orgId = $org->id;

            // 1. BANK ACCOUNTS & KAS (Linked to COA accounts, NO opening_balance)
            $accKas = Account::where('organization_id', $orgId)->where('code', '1110')->first();
            $accGiro308 = Account::where('organization_id', $orgId)->where('code', '1120')->first();
            $accGiro304 = Account::where('organization_id', $orgId)->where('code', '1130')->first();
            $accTab538 = Account::where('organization_id', $orgId)->where('code', '1140')->first();

            $bankAccounts = [
                [
                    'bank_name' => 'Bank BRI',
                    'account_number' => '0106-01-000308-30-8',
                    'account_name' => 'LP MAARIF NU CILACAP (GIRO 308)',
                    'branch' => 'Cilacap',
                    'type' => BankAccount::TYPE_GIRO,
                    'account_id' => $accGiro308?->id,
                ],
                [
                    'bank_name' => 'Bank BRI',
                    'account_number' => '0106-01-000304-30-0',
                    'account_name' => 'LP MAARIF NU CILACAP (GIRO 304)',
                    'branch' => 'Cilacap',
                    'type' => BankAccount::TYPE_GIRO,
                    'account_id' => $accGiro304?->id,
