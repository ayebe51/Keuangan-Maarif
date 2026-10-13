<?php

namespace App\Domain\Bank\Parsers;

use App\Domain\Bank\Exceptions\InvalidBankStatementFormatException;
use App\Domain\Bank\Parsers\Contracts\BankStatementParserInterface;
use App\Domain\Bank\Parsers\Contracts\StatementMappingProfileInterface;
use App\Domain\Bank\Parsers\Profiles\BriGiroMappingProfile;
use App\Domain\Bank\Parsers\Profiles\BriTabunganMappingProfile;
use App\Domain\Bank\Parsers\Profiles\BukuKasMappingProfile;
use App\Domain\Bank\Parsers\Profiles\GenericCsvMappingProfile;

class CsvBankStatementParser implements BankStatementParserInterface
{
    /** @var StatementMappingProfileInterface[] */
    protected array $profiles;

    public function __construct()
