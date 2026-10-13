<?php

namespace App\Domain\Bank\Parsers;

use App\Domain\Bank\Exceptions\InvalidBankStatementFormatException;
use App\Domain\Bank\Parsers\Contracts\BankStatementParserInterface;
use App\Domain\Bank\Parsers\Contracts\StatementMappingProfileInterface;
use App\Domain\Bank\Parsers\Profiles\BriGiroMappingProfile;
use App\Domain\Bank\Parsers\Profiles\BriTabunganMappingProfile;
use App\Domain\Bank\Parsers\Profiles\BukuKasMappingProfile;
use App\Domain\Bank\Parsers\Profiles\GenericCsvMappingProfile;
use SimpleXMLElement;
use ZipArchive;

class XlsxBankStatementParser implements BankStatementParserInterface
{
    /** @var StatementMappingProfileInterface[] */
    protected array $profiles;

    public function __construct()
    {
        $this->profiles = [
            new BukuKasMappingProfile(),
            new BriTabunganMappingProfile(),
            new BriGiroMappingProfile(),
            new GenericCsvMappingProfile(),
        ];
    }

    public function parse(string $filePath, array $options = []): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new InvalidBankStatementFormatException(basename($filePath), "File does not exist or is not readable.");
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new InvalidBankStatementFormatException(basename($filePath), "Could not open file as valid OpenXML XLSX archive.");
        }

        try {
            $sharedStrings = $this->loadSharedStrings($zip);
            $sheetInfo = $this->resolveSheetFile($zip, $options);
            $sheetFile = $sheetInfo['file'];
            $sheetName = $sheetInfo['name'];

            $sheetXmlContent = $zip->getFromName($sheetFile);
            if ($sheetXmlContent === false) {
                throw new InvalidBankStatementFormatException(basename($filePath), "Sheet XML '{$sheetFile}' not found in workbook.");
            }

