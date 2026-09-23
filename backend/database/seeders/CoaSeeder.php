<?php

namespace Database\Seeders;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CoaSeeder extends Seeder
{
    public function run(): void
    {
        $organizations = Organization::all();

        foreach ($organizations as $org) {
            $this->seedCoaForOrganization($org);
        }
    }

    public function seedCoaForOrganization(Organization $org): void
    {
        DB::transaction(function () use ($org) {
            $orgId = $org->id;

            // 1. ASSET (1000)
            $a1000 = Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '1000'],
                [
                    'parent_id' => null,
                    'account_type' => Account::TYPE_ASSET,
                    'normal_balance' => Account::BALANCE_DEBIT,
                    'name' => 'ASET',
                    'is_postable' => false,
                    'sort_order' => 10,
                ]
            );

            $a1100 = Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '1100'],
                [
                    'parent_id' => $a1000->id,
                    'account_type' => Account::TYPE_ASSET,
                    'normal_balance' => Account::BALANCE_DEBIT,
                    'name' => 'Kas dan Setara Kas',
                    'is_postable' => false,
                    'sort_order' => 20,
                ]
            );

            $accKas = Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '1110'],
                [
                    'parent_id' => $a1100->id,
                    'account_type' => Account::TYPE_ASSET,
                    'normal_balance' => Account::BALANCE_DEBIT,
                    'name' => 'Kas Tunai',
                    'is_postable' => true,
                    'sort_order' => 21,
                ]
            );

            $accGiro308 = Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '1120'],
                [
                    'parent_id' => $a1100->id,
                    'account_type' => Account::TYPE_ASSET,
                    'normal_balance' => Account::BALANCE_DEBIT,
                    'name' => 'Bank BRI Giro 308',
                    'is_postable' => true,
                    'sort_order' => 22,
                ]
            );

            $accGiro304 = Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '1130'],
                [
                    'parent_id' => $a1100->id,
                    'account_type' => Account::TYPE_ASSET,
                    'normal_balance' => Account::BALANCE_DEBIT,
                    'name' => 'Bank BRI Giro 304',
                    'is_postable' => true,
                    'sort_order' => 23,
                ]
            );

            $accTab538 = Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '1140'],
                [
                    'parent_id' => $a1100->id,
                    'account_type' => Account::TYPE_ASSET,
                    'normal_balance' => Account::BALANCE_DEBIT,
                    'name' => 'Bank BRI Tabungan 538',
                    'is_postable' => true,
                    'sort_order' => 24,
                ]
            );

            $a1200 = Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '1200'],
                [
                    'parent_id' => $a1000->id,
                    'account_type' => Account::TYPE_ASSET,
                    'normal_balance' => Account::BALANCE_DEBIT,
                    'name' => 'Piutang',
                    'is_postable' => false,
                    'sort_order' => 30,
                ]
            );

            Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '1210'],
                [
                    'parent_id' => $a1200->id,
                    'account_type' => Account::TYPE_ASSET,
                    'normal_balance' => Account::BALANCE_DEBIT,
                    'name' => 'Piutang Madrasah',
                    'is_postable' => true,
                    'sort_order' => 31,
                ]
            );

            // 2. LIABILITIES & FUNDS (2000)
            $a2000 = Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '2000'],
                [
                    'parent_id' => null,
                    'account_type' => Account::TYPE_LIABILITY,
                    'normal_balance' => Account::BALANCE_CREDIT,
                    'name' => 'KEWAJIBAN & DANA TERIKAT',
                    'is_postable' => false,
                    'sort_order' => 40,
                ]
            );

            $a2100 = Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '2100'],
                [
                    'parent_id' => $a2000->id,
                    'account_type' => Account::TYPE_LIABILITY,
                    'normal_balance' => Account::BALANCE_CREDIT,
                    'name' => 'Alokasi Dana Khusus',
                    'is_postable' => false,
                    'sort_order' => 50,
                ]
            );

            Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '2110'],
                [
                    'parent_id' => $a2100->id,
                    'account_type' => Account::TYPE_LIABILITY,
                    'normal_balance' => Account::BALANCE_CREDIT,
                    'name' => 'Alokasi Operasional Modul',
                    'is_postable' => true,
                    'sort_order' => 51,
                ]
            );

            Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '2120'],
                [
                    'parent_id' => $a2100->id,
                    'account_type' => Account::TYPE_LIABILITY,
                    'normal_balance' => Account::BALANCE_CREDIT,
                    'name' => 'Alokasi ASAS',
                    'is_postable' => true,
                    'sort_order' => 52,
                ]
            );

            Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '2200'],
                [
                    'parent_id' => $a2000->id,
                    'account_type' => Account::TYPE_LIABILITY,
                    'normal_balance' => Account::BALANCE_CREDIT,
                    'name' => 'Unapplied Cash / Titipan Belum Teridentifikasi',
                    'is_postable' => true,
                    'sort_order' => 60,
                ]
            );

            // 3. EQUITY (3000)
            $a3000 = Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '3000'],
                [
                    'parent_id' => null,
                    'account_type' => Account::TYPE_EQUITY,
                    'normal_balance' => Account::BALANCE_CREDIT,
                    'name' => 'EKUITAS',
                    'is_postable' => false,
                    'sort_order' => 70,
                ]
            );

            $accEquityOpening = Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '3100'],
                [
                    'parent_id' => $a3000->id,
                    'account_type' => Account::TYPE_EQUITY,
                    'normal_balance' => Account::BALANCE_CREDIT,
                    'name' => 'Saldo Awal / Ekuitas Pembukuan Awal',
                    'is_postable' => true,
                    'sort_order' => 71,
                ]
            );

            Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '3200'],
                [
                    'parent_id' => $a3000->id,
                    'account_type' => Account::TYPE_EQUITY,
                    'normal_balance' => Account::BALANCE_CREDIT,
                    'name' => 'Surplus / Defisit Periode Berjalan',
                    'is_postable' => true,
                    'sort_order' => 72,
                ]
            );

            // 4. REVENUE (4000)
            $a4000 = Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '4000'],
                [
                    'parent_id' => null,
                    'account_type' => Account::TYPE_REVENUE,
                    'normal_balance' => Account::BALANCE_CREDIT,
                    'name' => 'PENDAPATAN',
                    'is_postable' => false,
                    'sort_order' => 80,
                ]
            );

            Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '4100'],
                [
                    'parent_id' => $a4000->id,
                    'account_type' => Account::TYPE_REVENUE,
                    'normal_balance' => Account::BALANCE_CREDIT,
                    'name' => 'Pendapatan Iuran & Sertifikasi',
                    'is_postable' => true,
                    'sort_order' => 81,
                ]
            );

            Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '4200'],
                [
                    'parent_id' => $a4000->id,
                    'account_type' => Account::TYPE_REVENUE,
                    'normal_balance' => Account::BALANCE_CREDIT,
                    'name' => 'Pendapatan Jasa Giro & Bunga Bank',
                    'is_postable' => true,
                    'sort_order' => 82,
                ]
            );

            // 5. EXPENSE (5000)
            $a5000 = Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '5000'],
                [
                    'parent_id' => null,
                    'account_type' => Account::TYPE_EXPENSE,
                    'normal_balance' => Account::BALANCE_DEBIT,
                    'name' => 'BEBAN',
                    'is_postable' => false,
                    'sort_order' => 90,
                ]
            );

            Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '5100'],
                [
                    'parent_id' => $a5000->id,
                    'account_type' => Account::TYPE_EXPENSE,
                    'normal_balance' => Account::BALANCE_DEBIT,
                    'name' => 'Beban Operasional Kantor',
                    'is_postable' => true,
                    'sort_order' => 91,
                ]
            );

            Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '5200'],
                [
                    'parent_id' => $a5000->id,
                    'account_type' => Account::TYPE_EXPENSE,
                    'normal_balance' => Account::BALANCE_DEBIT,
                    'name' => 'Beban Administrasi Bank',
                    'is_postable' => true,
                    'sort_order' => 92,
                ]
            );

            Account::firstOrCreate(
                ['organization_id' => $orgId, 'code' => '5300'],
                [
                    'parent_id' => $a5000->id,
                    'account_type' => Account::TYPE_EXPENSE,
                    'normal_balance' => Account::BALANCE_DEBIT,
                    'name' => 'Beban Pajak Bunga Bank',
                    'is_postable' => true,
                    'sort_order' => 93,
                ]
            );

            // 6. Mapping Opening Balance Counterpart Account
            AccountMapping::firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'mapping_type' => 'opening_balance',
                    'mapping_key' => 'equity_counterpart',
                    'account_id' => $accEquityOpening->id,
                ],
                [
                    'mapping_value' => 'Account 3100',
                    'is_active' => true,
                ]
            );

            // 7. Seed Fiscal Period 2026-03 (Maret 2026)
            FiscalPeriod::firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'code' => '2026-03',
                ],
                [
                    'name' => 'Maret 2026',
                    'period_type' => 'monthly',
                    'start_date' => '2026-03-01',
                    'end_date' => '2026-03-31',
                    'status' => FiscalPeriod::STATUS_OPEN,
                    'is_current' => true,
                ]
            );

            // 8. Seed Bank Accounts & Mappings (if bank_accounts table exists)
            $bankGiro308 = DB::table('bank_accounts')->where('organization_id', $orgId)->where('account_number', '0106-01-000308-30-8')->first();
            if (!$bankGiro308) {
                $bId = DB::table('bank_accounts')->insertGetId([
                    'organization_id' => $orgId,
                    'bank_name' => 'Bank BRI',
                    'account_number' => '0106-01-000308-30-8',
                    'account_name' => 'LP MAARIF NU CILACAP (GIRO 308)',
                    'type' => 'giro',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $bId = $bankGiro308->id;
            }
            AccountMapping::firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'mapping_type' => AccountMapping::TYPE_BANK_ACCOUNT,
                    'mapping_key' => (string) $bId,
                    'account_id' => $accGiro308->id,
                ],
                ['is_active' => true]
            );

            $bankGiro304 = DB::table('bank_accounts')->where('organization_id', $orgId)->where('account_number', '0106-01-000304-30-0')->first();
            if (!$bankGiro304) {
                $bId304 = DB::table('bank_accounts')->insertGetId([
                    'organization_id' => $orgId,
                    'bank_name' => 'Bank BRI',
                    'account_number' => '0106-01-000304-30-0',
                    'account_name' => 'LP MAARIF NU CILACAP (GIRO 304)',
                    'type' => 'giro',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $bId304 = $bankGiro304->id;
            }
            AccountMapping::firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'mapping_type' => AccountMapping::TYPE_BANK_ACCOUNT,
                    'mapping_key' => (string) $bId304,
                    'account_id' => $accGiro304->id,
                ],
                ['is_active' => true]
            );

            $bankTab538 = DB::table('bank_accounts')->where('organization_id', $orgId)->where('account_number', '0106-01-000538-53-8')->first();
            if (!$bankTab538) {
                $bId538 = DB::table('bank_accounts')->insertGetId([
                    'organization_id' => $orgId,
                    'bank_name' => 'Bank BRI',
                    'account_number' => '0106-01-000538-53-8',
                    'account_name' => 'LP MAARIF NU CILACAP (TABUNGAN 538)',
                    'type' => 'savings',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $bId538 = $bankTab538->id;
            }
            AccountMapping::firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'mapping_type' => AccountMapping::TYPE_BANK_ACCOUNT,
                    'mapping_key' => (string) $bId538,
                    'account_id' => $accTab538->id,
                ],
                ['is_active' => true]
            );
        });
    }
}
