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
