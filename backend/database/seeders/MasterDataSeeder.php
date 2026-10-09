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
                ],
                [
                    'bank_name' => 'Bank BRI',
                    'account_number' => '0106-01-000538-53-8',
                    'account_name' => 'LP MAARIF NU CILACAP (TABUNGAN 538)',
                    'branch' => 'Cilacap',
                    'type' => BankAccount::TYPE_SAVINGS,
                    'account_id' => $accTab538?->id,
                ],
                [
                    'bank_name' => 'Kas Tunai',
                    'account_number' => 'KAS-TUNAI-01',
                    'account_name' => 'Kas Tunai Bendahara',
                    'branch' => 'Kantor PC LP Ma\'arif',
                    'type' => BankAccount::TYPE_CASH,
                    'account_id' => $accKas?->id,
                ],
            ];

            foreach ($bankAccounts as $baData) {
                $ba = BankAccount::firstOrNew([
                    'organization_id' => $orgId,
                    'account_number' => $baData['account_number'],
                ]);

                $ba->bank_name = $baData['bank_name'];
                $ba->account_name = $baData['account_name'];
                $ba->branch = $baData['branch'];
                $ba->currency = 'IDR';
                $ba->type = $baData['type'];
                $ba->account_id = $baData['account_id'];
                $ba->is_active = true;
                $ba->save();

                if ($ba->account_id) {
                    AccountMapping::firstOrCreate(
                        [
                            'organization_id' => $orgId,
                            'mapping_type' => AccountMapping::TYPE_BANK_ACCOUNT,
                            'mapping_key' => (string) $ba->id,
                        ],
                        [
                            'account_id' => $ba->account_id,
                            'is_active' => true,
                        ]
                    );
                }
            }

            // 2. COUNTERPARTIES & SYSTEM ALIASES
            // 2.1 System Bank BRI
            $bankBri = Counterparty::firstOrCreate(
                ['organization_id' => $orgId, 'code' => 'BANK-BRI'],
                [
                    'role' => Counterparty::ROLE_BANK,
                    'name' => 'Bank BRI',
                    'is_active' => true,
                ]
            );

            $bankBriAliases = [
                'Bank BRI',
                'BRI',
                'Adm Bank',
                'Pajak Bulanan (Bank)',
                'Bunga Rekening (Bank)',
                'BIAYA ADM',
                'PAJAK BUNGA',
            ];
            foreach ($bankBriAliases as $alias) {
                CounterpartyAlias::firstOrCreate(
                    [
                        'organization_id' => $orgId,
                        'counterparty_id' => $bankBri->id,
                        'alias_name' => $alias,
                    ],
                    ['source' => CounterpartyAlias::SOURCE_SYSTEM]
                );
            }

            // 2.2 System Internal LP Ma'arif
            $internalMaarif = Counterparty::firstOrCreate(
                ['organization_id' => $orgId, 'code' => 'INTERNAL-MAARIF'],
                [
                    'role' => Counterparty::ROLE_INTERNAL,
                    'name' => 'LP Ma\'arif NU',
                    'is_active' => true,
                ]
            );
            $internalAliases = ['LP Ma\'arif NU', 'Internal LP Maarif', 'Maarif NU Cilacap'];
            foreach ($internalAliases as $alias) {
                CounterpartyAlias::firstOrCreate(
                    [
                        'organization_id' => $orgId,
                        'counterparty_id' => $internalMaarif->id,
                        'alias_name' => $alias,
                    ],
                    ['source' => CounterpartyAlias::SOURCE_SYSTEM]
                );
            }

            // 2.3 Key Madrasah / Sekolah (Golden Dataset Counterparties)
            $schools = [
                [
                    'code' => 'MI-DARWATA-01',
                    'name' => 'MI Darwata Sindangbarang',
                    'aliases' => ['MI Darwata Sindangbarang', 'MI Darwata', 'Darwata'],
                ],
                [
                    'code' => 'MI-KESUGIHAN-01',
                    'name' => 'MI Ma\'arif 01 Kesugihan',
                    'aliases' => ['MI Ma\'arif 01 Kesugihan', 'MI 01 Kesugihan'],
                ],
                [
                    'code' => 'MTS-CILACAP-01',
                    'name' => 'MTs Ma\'arif 01 Cilacap',
                    'aliases' => ['MTs Ma\'arif 01 Cilacap', 'MTs 01 Cilacap'],
                ],
                [
                    'code' => 'SMK-KROYA-01',
                    'name' => 'SMK Ma\'arif 1 Kroya',
                    'aliases' => ['SMK Ma\'arif 1 Kroya', 'SMK 1 Kroya'],
                ],
            ];

            foreach ($schools as $sch) {
                $cp = Counterparty::firstOrCreate(
                    ['organization_id' => $orgId, 'code' => $sch['code']],
                    [
                        'role' => Counterparty::ROLE_SCHOOL,
                        'name' => $sch['name'],
                        'is_active' => true,
                    ]
                );

                foreach ($sch['aliases'] as $alias) {
                    CounterpartyAlias::firstOrCreate(
                        [
                            'organization_id' => $orgId,
                            'counterparty_id' => $cp->id,
                            'alias_name' => $alias,
                        ],
                        ['source' => CounterpartyAlias::SOURCE_MANUAL]
                    );
                }
            }

            // 3. TRANSACTION CATEGORIES
            $categories = [
                // Penerimaan (IN)
                [
                    'code' => 'IN-BOS',
                    'name' => 'Dana Bantuan Operasional Sekolah (BOS)',
                    'direction' => TransactionCategory::DIRECTION_IN,
                    'description' => 'Penerimaan dana BOS madrasah dari pemerintah / rekening penampung',
                ],
                [
                    'code' => 'IN-INFAQ',
                    'name' => 'Penerimaan Infaq & Shodaqoh',
                    'direction' => TransactionCategory::DIRECTION_IN,
                    'description' => 'Penerimaan infaq madrasah dan donatur',
                ],
                [
                    'code' => 'IN-IURAN',
                    'name' => 'Iuran Wajib Madrasah / Sekolah',
                    'direction' => TransactionCategory::DIRECTION_IN,
                    'description' => 'Iuran bulanan anggota madrasah LP Ma\'arif',
                ],
                [
                    'code' => 'IN-BUNGA-BANK',
                    'name' => 'Pendapatan Bunga Rekening Bank',
                    'direction' => TransactionCategory::DIRECTION_IN,
                    'description' => 'Bunga bulanan dari bank penampung',
                ],

                // Pengeluaran (OUT)
                [
                    'code' => 'OUT-OPS-KANTOR',
                    'name' => 'Beban Operasional Kantor',
                    'direction' => TransactionCategory::DIRECTION_OUT,
                    'description' => 'Pengeluaran ATK, listrik, internet, dan kebersihan kantor',
                ],
                [
                    'code' => 'OUT-ADM-BANK',
                    'name' => 'Beban Administrasi Bank',
                    'direction' => TransactionCategory::DIRECTION_OUT,
                    'description' => 'Potongan biaya administrasi dan buku cek bank',
                ],
                [
                    'code' => 'OUT-PAJAK-BUNGA',
                    'name' => 'Beban Pajak Bunga Bank',
                    'direction' => TransactionCategory::DIRECTION_OUT,
                    'description' => 'Potongan pajak atas pendapatan bunga bank',
                ],
                [
                    'code' => 'OUT-HONOR',
                    'name' => 'Honorarium Pengurus & Tenaga Ahli',
                    'direction' => TransactionCategory::DIRECTION_OUT,
                    'description' => 'Insentif dan honorarium kerja staf pengurus',
                ],

                // Pemindahan Dana (TRANSFER)
                [
                    'code' => 'TRF-KAS-BANK',
                    'name' => 'Setoran Kas Tunai ke Bank',
                    'direction' => TransactionCategory::DIRECTION_TRANSFER,
                    'description' => 'Penyetoran kas fisik ke rekening bank',
                ],
                [
                    'code' => 'TRF-BANK-KAS',
                    'name' => 'Penarikan Kas Tunai dari Bank',
                    'direction' => TransactionCategory::DIRECTION_TRANSFER,
                    'description' => 'Penarikan uang tunai untuk kas bendahara',
                ],
                [
                    'code' => 'TRF-ANTAR-REK',
                    'name' => 'Pemindahbukuan Antar Rekening Bank',
                    'direction' => TransactionCategory::DIRECTION_TRANSFER,
                    'description' => 'Transfer dana antar rekening Giro dan Tabungan',
                ],
            ];

            foreach ($categories as $cat) {
                TransactionCategory::firstOrCreate(
                    ['organization_id' => $orgId, 'code' => $cat['code']],
                    [
                        'name' => $cat['name'],
                        'direction' => $cat['direction'],
                        'description' => $cat['description'] ?? null,
                        'is_active' => true,
                    ]
                );
            }

            // 4. FUNDS (Master Dana Cadangan & Program)
            $funds = [
                [
                    'code' => 'DANA-ABADI',
                    'name' => 'Dana Abadi Pendidikan Ma\'arif',
                    'fund_type' => Fund::TYPE_RESTRICTED,
                    'budget_amount' => 500000000,
                    'description' => 'Dana pokok abadi untuk kemaslahatan pendidikan',
                ],
                [
                    'code' => 'DANA-RAMADHAN',
                    'name' => 'Dana Kegiatan Ramadhan 1447H',
                    'fund_type' => Fund::TYPE_TEMPORARILY_RESTRICTED,
                    'budget_amount' => 50000000,
                    'description' => 'Alokasi khusus operasional safari Ramadhan',
                ],
                [
                    'code' => 'DANA-BEASISWA',
                    'name' => 'Dana Beasiswa Siswa Dhuafa & Yatim',
                    'fund_type' => Fund::TYPE_RESTRICTED,
                    'budget_amount' => 100000000,
                    'description' => 'Bantuan SPP dan seragam siswa kurang mampu',
                ],
            ];

            foreach ($funds as $fData) {
                Fund::firstOrCreate(
                    ['organization_id' => $orgId, 'code' => $fData['code']],
                    [
                        'name' => $fData['name'],
                        'fund_type' => $fData['fund_type'],
                        'budget_amount' => $fData['budget_amount'],
                        'description' => $fData['description'],
                        'status' => Fund::STATUS_ACTIVE,
                        'is_active' => true,
                    ]
                );
            }

            // 5. PROGRAMS (Master Dimensi Program)
            $programs = [
                [
                    'code' => 'PROG-BOS',
                    'name' => 'Program Pengelolaan BOS Madrasah',
                    'description' => 'Pendampingan dan administrasi BOS satuan pendidikan',
                ],
                [
                    'code' => 'PROG-KURIKULUM',
                    'name' => 'Program Pengembangan Kurikulum & Literasi Aswaja',
                    'description' => 'Pelatihan guru dan penerbitan modul ajar Aswaja',
                ],
                [
                    'code' => 'PROG-HARLAH',
                    'name' => 'Peringatan Hari Lahir LP Ma\'arif NU',
                    'description' => 'Rangkaian lomba olimpiade sains dan perkemahan Ma\'arif',
                ],
            ];

            foreach ($programs as $prog) {
                Program::firstOrCreate(
                    ['organization_id' => $orgId, 'code' => $prog['code']],
                    [
                        'name' => $prog['name'],
                        'description' => $prog['description'],
                        'is_active' => true,
                    ]
                );
            }
        });
    }
}
