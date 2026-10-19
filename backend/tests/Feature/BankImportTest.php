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
