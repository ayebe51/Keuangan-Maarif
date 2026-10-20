<?php

namespace Tests\Feature;

use App\Domain\Bank\Enums\BankImportStatus;
use App\Domain\Bank\Enums\BankTransactionDirection;
use App\Domain\Bank\Enums\BankTransactionStatus;
use App\Domain\Bank\Exceptions\DuplicateImportException;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Models\BankImport;
use App\Domain\Bank\Models\BankRawSource;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Bank\Services\BankImportService;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class BankImportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;
    private Organization $orgB;
    private User $adminA;
    private User $adminB;
    private BankAccount $account308;
    private BankAccount $account304;
    private BankAccount $account538;
    private BankImportService $importService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\CoaSeeder::class);
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $this->orgA = Organization::where('code', 'LP-MAARIF-CLP')->firstOrFail();
        $this->adminA = User::where('email', 'admin@maarif-cilacap.org')->firstOrFail();

        $this->orgB = Organization::create([
            'code' => 'ORG-TEST-B',
            'name' => 'Organisasi Test B',
            'type' => 'madrasah',
            'is_active' => true,
        ]);

        $this->adminB = User::create([
            'organization_id' => $this->orgB->id,
            'name' => 'Admin Org B',
            'email' => 'admin-b@test.org',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
        TenantContext::setTenantId($this->orgA->id);

        $this->account308 = BankAccount::where('account_number', '0106-01-000308-30-8')->firstOrFail();
        $this->account304 = BankAccount::where('account_number', '0106-01-000304-30-0')->firstOrFail();
        $this->account538 = BankAccount::where('account_number', '0106-01-000538-53-8')->firstOrFail();

        $this->importService = app(BankImportService::class);
    }

    /**
     * Helper to create a valid minimal XLSX statement file for testing.
     */
    private function createSampleXlsx(string $filename, string $sheetName, array $headers, array $rows): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($path)) {
            unlink($path);
        }

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // 2. _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // 3. xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // 4. xl/workbook.xml
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="' . htmlspecialchars($sheetName) . '" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>';
        $zip->addFromString('xl/workbook.xml', $wb);

        // Collect strings and build sheet rows
        $sharedStrings = [];
        $stringIndexMap = [];
        $getStringIdx = function(string $str) use (&$sharedStrings, &$stringIndexMap) {
            if (isset($stringIndexMap[$str])) {
                return $stringIndexMap[$str];
            }
            $idx = count($sharedStrings);
            $sharedStrings[] = $str;
            $stringIndexMap[$str] = $idx;
            return $idx;
        };

        $sheetRowsXml = '';
        $rowNum = 1;

        // Header row
        $sheetRowsXml .= '<row r="' . $rowNum . '">';
        foreach ($headers as $colIdx => $headerText) {
            $colLetter = chr(65 + $colIdx);
            $sIdx = $getStringIdx((string)$headerText);
            $sheetRowsXml .= '<c r="' . $colLetter . $rowNum . '" t="s"><v>' . $sIdx . '</v></c>';
        }
        $sheetRowsXml .= '</row>';

        // Data rows
        foreach ($rows as $dataRow) {
            $rowNum++;
            $sheetRowsXml .= '<row r="' . $rowNum . '">';
            foreach ($dataRow as $colIdx => $val) {
                $colLetter = chr(65 + $colIdx);
                if (is_numeric($val)) {
                    $sheetRowsXml .= '<c r="' . $colLetter . $rowNum . '"><v>' . $val . '</v></c>';
                } else {
                    $sIdx = $getStringIdx((string)$val);
                    $sheetRowsXml .= '<c r="' . $colLetter . $rowNum . '" t="s"><v>' . $sIdx . '</v></c>';
                }
            }
            $sheetRowsXml .= '</row>';
        }

        // 5. xl/worksheets/sheet1.xml
        $ws = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>' . $sheetRowsXml . '</sheetData>
</worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $ws);

        // 6. xl/sharedStrings.xml
        $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">';
        foreach ($sharedStrings as $str) {
            $ssXml .= '<si><t>' . htmlspecialchars($str) . '</t></si>';
        }
        $ssXml .= '</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $ssXml);

        $zip->close();

        return $path;
    }

    /**
     * Helper to create a CSV statement file.
     */
    private function createSampleCsv(string $filename, array $headers, array $rows, string $delimiter = ','): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
        $fp = fopen($path, 'w');
        fputcsv($fp, $headers, $delimiter);
        foreach ($rows as $row) {
            fputcsv($fp, $row, $delimiter);
        }
        fclose($fp);
        return $path;
    }

    /**
     * Test 1: CSV Statement Ingestion (Normal Flow)
     */
    public function test_csv_statement_ingestion_normal_flow(): void
    {
        Sanctum::actingAs($this->adminA);
        TenantContext::setTenantId($this->orgA->id);

        $csvPath = $this->createSampleCsv('statement_308.csv', [
            'Tanggal', 'Keterangan', 'Debet', 'Kredit', 'Saldo'
        ], [
            ['2026-03-01', 'Setoran Awal Infaq', '', '1.000.000,00', '73.125.396,20'],
            ['2026-03-05', 'Biaya Materai & Administrasi', '5.539,00', '', '73.119.857,20'],
        ]);

        $file = new UploadedFile($csvPath, 'statement_308.csv', 'text/csv', null, true);

        $response = $this->postJson('/api/v1/bank-imports', [
            'file' => $file,
            'bank_account_id' => $this->account308->id,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'message' => 'Bank statement imported successfully.',
            'data' => [
                'total_rows' => 2,
                'imported_rows' => 2,
                'skipped_rows' => 0,
                'error_rows' => 0,
                'status' => 'done',
            ],
        ]);

        $this->assertDatabaseHas('bank_imports', [
            'bank_account_id' => $this->account308->id,
            'total_rows' => 2,
            'imported_rows' => 2,
            'status' => 'done',
        ]);

        $this->assertDatabaseCount('bank_transactions', 2);
    }

    /**
     * Test 2: XLSX Statement Ingestion (Native OpenXML Streaming & Serial Date)
     */
    public function test_xlsx_statement_ingestion_openxml_and_serial_date(): void
    {
        Sanctum::actingAs($this->adminA);
        TenantContext::setTenantId($this->orgA->id);

        // 46082 is 2026-03-01 in Excel serial date format
        $xlsxPath = $this->createSampleXlsx('statement_308.xlsx', 'REKAP REK GIRO 308', [
            'Tanggal', 'Keterangan', 'Debet', 'Kredit', 'Saldo'
        ], [
            [46082, 'Bunga Tabungan BRI', '', 150000, 72275396.20],
            [46083, 'Pajak Bunga Bank', 30000, '', 72245396.20],
        ]);

        $file = new UploadedFile($xlsxPath, 'statement_308.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->postJson('/api/v1/bank-imports', [
            'file' => $file,
            'bank_account_id' => $this->account308->id,
            'sheet_name' => 'REKAP REK GIRO 308',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'data' => [
                'imported_rows' => 2,
                'period_start' => '2026-03-01',
                'period_end' => '2026-03-02',
            ],
        ]);

        $this->assertDatabaseHas('bank_transactions', [
            'bank_account_id' => $this->account308->id,
            'transaction_date' => '2026-03-01',
            'amount' => '150000.00',
            'direction' => 'IN',
        ]);
    }

    /**
     * Test 3: Bank Semantics Invariant (Bank Credit = IN, Bank Debit = OUT)
     */
    public function test_bank_semantics_invariant_credit_is_in_debit_is_out(): void
    {
        Sanctum::actingAs($this->adminA);
        TenantContext::setTenantId($this->orgA->id);

        $csvPath = $this->createSampleCsv('statement_semantics.csv', [
            'Tanggal', 'Keterangan', 'Debet', 'Kredit', 'Saldo'
        ], [
            ['2026-03-10', 'Setoran Pembayaran Madrasah', '', '852.000,00', '1.000.000,00'],
            ['2026-03-11', 'Penarikan Kas Operasional', '250.000,00', '', '750.000,00'],
        ]);

        $this->importService->importFile($csvPath, $this->account308->id, $this->adminA);

        $txIn = BankTransaction::where('description', 'LIKE', '%SETORAN%')->firstOrFail();
        $txOut = BankTransaction::where('description', 'LIKE', '%PENARIKAN%')->firstOrFail();

        // INVARIANT 1: Bank Credit => Cash Received (IN)
        $this->assertEquals(BankTransactionDirection::IN, $txIn->direction);
        $this->assertEquals('852000.00', $txIn->amount);
        $this->assertTrue($txIn->isCashIn());

        // INVARIANT 2: Bank Debit => Cash Disbursed (OUT)
        $this->assertEquals(BankTransactionDirection::OUT, $txOut->direction);
        $this->assertEquals('250000.00', $txOut->amount);
        $this->assertTrue($txOut->isCashOut());
    }

    /**
     * Test 4: Idempotency & Zero Financial Duplication (Phase Gate Core)
     */
    public function test_idempotency_file_and_row_duplicate_prevention(): void
    {
        Sanctum::actingAs($this->adminA);
        TenantContext::setTenantId($this->orgA->id);

        $csvPath = $this->createSampleCsv('file_original.csv', [
            'Tanggal', 'Keterangan', 'Debet', 'Kredit', 'Saldo'
        ], [
            ['2026-03-01', 'Transaksi Row 1', '', '100.000,00', '100.000,00'],
            ['2026-03-02', 'Transaksi Row 2', '20.000,00', '', '80.000,00'],
        ]);

        // First upload: SUCCESS
        $file1 = new UploadedFile($csvPath, 'file_original.csv', 'text/csv', null, true);
        $resp1 = $this->postJson('/api/v1/bank-imports', [
            'file' => $file1,
            'bank_account_id' => $this->account308->id,
        ]);
        $resp1->assertStatus(201);
        $this->assertDatabaseCount('bank_transactions', 2);

        // Sub-test 4a: Second upload of identical file is blocked (HTTP 409)
        $file2 = new UploadedFile($csvPath, 'file_original.csv', 'text/csv', null, true);
        $resp2 = $this->postJson('/api/v1/bank-imports', [
            'file' => $file2,
            'bank_account_id' => $this->account308->id,
        ]);
        $resp2->assertStatus(409);
        $resp2->assertJson([
            'error' => 'DUPLICATE_IMPORT',
        ]);
        $this->assertDatabaseCount('bank_transactions', 2); // Still exactly 2!

        // Sub-test 4b: New file with 2 old rows and 2 new rows
        $overlappingCsv = $this->createSampleCsv('file_overlap.csv', [
            'Tanggal', 'Keterangan', 'Debet', 'Kredit', 'Saldo'
        ], [
            ['2026-03-01', 'Transaksi Row 1', '', '100.000,00', '100.000,00'], // Old (skip)
            ['2026-03-02', 'Transaksi Row 2', '20.000,00', '', '80.000,00'],  // Old (skip)
            ['2026-03-03', 'Transaksi Row 3 Baru', '', '50.000,00', '130.000,00'], // New (import)
            ['2026-03-04', 'Transaksi Row 4 Baru', '10.000,00', '', '120.000,00'], // New (import)
        ]);

        $file3 = new UploadedFile($overlappingCsv, 'file_overlap.csv', 'text/csv', null, true);
        $resp3 = $this->postJson('/api/v1/bank-imports', [
            'file' => $file3,
            'bank_account_id' => $this->account308->id,
        ]);
        $resp3->assertStatus(201);
        $resp3->assertJson([
            'data' => [
                'total_rows' => 4,
                'imported_rows' => 2,
                'skipped_rows' => 2,
            ],
        ]);

        // CRITICAL INVARIANT: Zero duplicate financial transactions created! Total must be 4.
        $this->assertDatabaseCount('bank_transactions', 4);
    }

    /**
     * Test 5: Same-Day Multiple Identical Transactions Disambiguation (row_sequence)
     */
    public function test_same_day_multiple_identical_transactions_preserved(): void
    {
        Sanctum::actingAs($this->adminA);
        TenantContext::setTenantId($this->orgA->id);

        $csvPath = $this->createSampleCsv('same_day_twins.csv', [
            'Tanggal', 'Keterangan', 'Debet', 'Kredit', 'Saldo'
        ], [
            ['2026-03-15', 'BIAYA ADM BANK BRI', '2.500,00', '', '97.500,00'],
            ['2026-03-15', 'BIAYA ADM BANK BRI', '2.500,00', '', '95.000,00'], // Identical amount, desc, date
        ]);

        $import = $this->importService->importFile($csvPath, $this->account308->id, $this->adminA);

        $this->assertEquals(2, $import->imported_rows);
        $this->assertEquals(0, $import->skipped_rows);

        $txs = BankTransaction::where('transaction_date', '2026-03-15')
            ->where('amount', '2500.00')
            ->orderBy('row_sequence')
            ->get();

        $this->assertCount(2, $txs);
        $this->assertEquals(1, $txs[0]->row_sequence);
        $this->assertEquals(2, $txs[1]->row_sequence);
        $this->assertNotEquals($txs[0]->fingerprint, $txs[1]->fingerprint);
    }

    /**
     * Test 6: Raw Source Preservation & Drill-Down Audit
     */
    public function test_raw_source_preservation_and_drill_down_audit(): void
    {
        Sanctum::actingAs($this->adminA);
        TenantContext::setTenantId($this->orgA->id);

        $csvPath = $this->createSampleCsv('audit_sample.csv', [
            'Tanggal', 'Keterangan', 'Debet', 'Kredit', 'Saldo'
        ], [
            ['2026-03-20', 'Dana BOS Batch 1', '', '50.000.000,00', '50.000.000,00'],
        ]);

        $import = $this->importService->importFile($csvPath, $this->account308->id, $this->adminA);

        $tx = BankTransaction::where('bank_import_id', $import->id)->firstOrFail();
        $this->assertNotNull($tx->raw_source_id);

        $rawSource = BankRawSource::find($tx->raw_source_id);
        $this->assertNotNull($rawSource);
        $this->assertEquals('audit_sample.csv', $rawSource->source_file);
        $this->assertEquals(2, $rawSource->source_row);
        $this->assertIsArray($rawSource->raw_data);

        // API Drill-Down assertion
        $response = $this->getJson("/api/v1/bank-transactions/{$tx->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'id' => $tx->id,
                'raw_source' => [
                    'source_file' => 'audit_sample.csv',
                    'source_row' => 2,
                ],
            ],
        ]);
    }

    /**
     * Test 7: Running Balance Discrepancy Detection (REV3-06)
     */
    public function test_running_balance_discrepancy_flagged_as_audit_exception(): void
    {
        Sanctum::actingAs($this->adminA);
        TenantContext::setTenantId($this->orgA->id);

        // Row 1: Saldo 100.000, +50.000 => Saldo seharusnya 150.000, tapi bank reported 180.000! (Gap 30.000)
        $csvPath = $this->createSampleCsv('discrepancy_statement.csv', [
            'Tanggal', 'Keterangan', 'Debet', 'Kredit', 'Saldo'
        ], [
            ['2026-03-01', 'Setoran 1', '', '100.000,00', '100.000,00'],
            ['2026-03-02', 'Setoran 2', '', '50.000,00', '180.000,00'],
        ]);

        $import = $this->importService->importFile($csvPath, $this->account308->id, $this->adminA);

        $this->assertTrue($import->has_discrepancies);

        // Verify exception queue API
        $response = $this->getJson("/api/v1/bank-imports/{$import->id}/exceptions");
        $response->assertStatus(200);
        $response->assertJsonFragment([
            'error_code' => 'BALANCE_DISCREPANCY',
        ]);
    }

    /**
     * Test 8: Counterparty Auto-Resolution Integration
     */
    public function test_counterparty_auto_resolution_links_correct_entity(): void
    {
        Sanctum::actingAs($this->adminA);
        TenantContext::setTenantId($this->orgA->id);

        // Setup counterparties from seeder: Bank BRI, MI Darwata
        $bri = Counterparty::where('code', 'BANK-BRI')->firstOrFail();
        $miDarwata = Counterparty::where('code', 'MI-DARWATA-01')->firstOrFail();

        $csvPath = $this->createSampleCsv('cp_resolution.csv', [
            'Tanggal', 'Keterangan', 'Debet', 'Kredit', 'Saldo'
        ], [
            ['2026-03-05', 'BIAYA ADM BANK BRI MARET', '12.000,00', '', '1.000.000,00'],
            ['2026-03-06', 'SETORAN DANA INFAQ DARI MI DARWATA SINDANGBARANG', '', '852.000,00', '1.852.000,00'],
        ]);

        $this->importService->importFile($csvPath, $this->account308->id, $this->adminA);

        $txBri = BankTransaction::where('description', 'LIKE', '%ADM BANK BRI%')->firstOrFail();
        $txMadrasah = BankTransaction::where('description', 'LIKE', '%MI DARWATA%')->firstOrFail();

        $this->assertEquals($bri->id, $txBri->counterparty_id);
        $this->assertEquals($miDarwata->id, $txMadrasah->counterparty_id);
    }

    /**
     * Test 9: Golden Dataset Baseline Reconciliation (REV3-13 Exact March 2026 Totals)
     */
    public function test_golden_dataset_march_2026_totals_reconciled(): void
    {
        Sanctum::actingAs($this->adminA);
        TenantContext::setTenantId($this->orgA->id);

        // 1. BRI Giro 308: Debet Rp5.539,00, Kredit Rp9.077.695,00
        $csv308 = $this->createSampleCsv('golden_308.csv', [
            'Tanggal', 'Keterangan', 'Debet', 'Kredit', 'Saldo'
        ], [
            ['2026-03-01', 'Setoran Penerimaan Giro 308', '', '9.077.695,00', '81.203.091,20'],
            ['2026-03-02', 'Beban Administrasi Buku Cek 308', '5.539,00', '', '81.197.552,20'],
        ]);
        $this->importService->importFile($csv308, $this->account308->id, $this->adminA);

        $debit308 = BankTransaction::where('bank_account_id', $this->account308->id)
            ->where('direction', BankTransactionDirection::OUT)
            ->sum('amount');
        $credit308 = BankTransaction::where('bank_account_id', $this->account308->id)
            ->where('direction', BankTransactionDirection::IN)
            ->sum('amount');

        $this->assertEquals('5539.00', number_format($debit308, 2, '.', ''));
        $this->assertEquals('9077695.00', number_format($credit308, 2, '.', ''));

        // 2. BRI Giro 304: Debet Rp124.258.949,00, Kredit Rp101.846.004,00
        $csv304 = $this->createSampleCsv('golden_304.csv', [
            'Tanggal', 'Keterangan', 'Debet', 'Kredit', 'Saldo'
        ], [
            ['2026-03-05', 'Penerimaan Piutang & Setoran Giro 304', '', '101.846.004,00', '571.622.289,00'],
            ['2026-03-10', 'Pencairan Operasional & Transfer Giro 304', '124.258.949,00', '', '447.363.340,00'],
        ]);
        $this->importService->importFile($csv304, $this->account304->id, $this->adminA);

        $debit304 = BankTransaction::where('bank_account_id', $this->account304->id)
            ->where('direction', BankTransactionDirection::OUT)
            ->sum('amount');
        $credit304 = BankTransaction::where('bank_account_id', $this->account304->id)
            ->where('direction', BankTransactionDirection::IN)
            ->sum('amount');

        $this->assertEquals('124258949.00', number_format($debit304, 2, '.', ''));
        $this->assertEquals('101846004.00', number_format($credit304, 2, '.', ''));

        // 3. BRI Tabungan 538: Debet Rp61.193,00, Kredit Rp109.362.464,00
        $csv538 = $this->createSampleCsv('golden_538.csv', [
            'Tanggal', 'Keterangan', 'Debet', 'Kredit', 'Saldo'
        ], [
            ['2026-03-15', 'Penerimaan Setoran Tabungan 538', '', '109.362.464,00', '681.318.120,80'],
            ['2026-03-20', 'Pajak & Biaya Tabungan 538', '61.193,00', '', '681.256.927,80'],
        ]);
        $this->importService->importFile($csv538, $this->account538->id, $this->adminA);

        $debit538 = BankTransaction::where('bank_account_id', $this->account538->id)
            ->where('direction', BankTransactionDirection::OUT)
            ->sum('amount');
        $credit538 = BankTransaction::where('bank_account_id', $this->account538->id)
            ->where('direction', BankTransactionDirection::IN)
            ->sum('amount');

        $this->assertEquals('61193.00', number_format($debit538, 2, '.', ''));
        $this->assertEquals('109362464.00', number_format($credit538, 2, '.', ''));
    }

    /**
     * Test 10: Multi-Tenant Isolation
     */
    public function test_multi_tenant_isolation_strictly_enforced(): void
    {
        Sanctum::actingAs($this->adminA);
        TenantContext::setTenantId($this->orgA->id);

        $csvPath = $this->createSampleCsv('tenant_a_stmt.csv', [
            'Tanggal', 'Keterangan', 'Debet', 'Kredit', 'Saldo'
        ], [
            ['2026-03-01', 'Setoran Rahasia Org A', '', '1.000.000,00', '1.000.000,00'],
        ]);

        $importA = $this->importService->importFile($csvPath, $this->account308->id, $this->adminA);
        $txA = BankTransaction::where('bank_import_id', $importA->id)->firstOrFail();

        // Switch to Admin Tenant B
        Sanctum::actingAs($this->adminB);
        TenantContext::setTenantId($this->orgB->id);

        // Tenant B cannot view Tenant A's import batch
        $respImport = $this->getJson("/api/v1/bank-imports/{$importA->id}");
        $respImport->assertStatus(404);

        // Tenant B cannot view Tenant A's bank transactions
        $respTx = $this->getJson("/api/v1/bank-transactions/{$txA->id}");
        $respTx->assertStatus(404);

        // Tenant B listing bank transactions gets empty list
        $respList = $this->getJson('/api/v1/bank-transactions');
        $respList->assertStatus(200);
        $respList->assertJsonCount(0, 'data');
    }

    /**
     * Test 11: Error Resilience & Anomaly Isolation (Corrupt Rows)
     */
    public function test_error_resilience_corrupt_rows_do_not_abort_batch(): void
    {
        Sanctum::actingAs($this->adminA);
        TenantContext::setTenantId($this->orgA->id);

        $csvPath = $this->createSampleCsv('resilience_sample.csv', [
            'Tanggal', 'Keterangan', 'Debet', 'Kredit', 'Saldo'
        ], [
            ['2026-03-01', 'Baris Valid 1', '', '100.000,00', '100.000,00'],
            ['TANGGAL_KORUP', 'Baris Error Tanggal', '', '50.000,00', '150.000,00'], // Corrupt date
            ['2026-03-03', 'Baris Error Amount', 'BUKAN_ANGKA', 'BUKAN_ANGKA', '150.000,00'], // Corrupt amount
            ['2026-03-04', 'Baris Valid 2', '25.000,00', '', '125.000,00'],
        ]);

        $import = $this->importService->importFile($csvPath, $this->account308->id, $this->adminA);

        $this->assertEquals(4, $import->total_rows);
        $this->assertEquals(2, $import->imported_rows);
        $this->assertEquals(2, $import->error_rows);
        $this->assertEquals(0, $import->skipped_rows);
        $this->assertEquals(BankImportStatus::DONE, $import->status);

        $this->assertDatabaseCount('bank_transactions', 2);
        $this->assertDatabaseCount('bank_raw_sources', 4);

        $errors = BankRawSource::where('bank_import_id', $import->id)
            ->where('status', BankRawSource::STATUS_ERROR)
            ->get();

        $this->assertCount(2, $errors);
    }
}
