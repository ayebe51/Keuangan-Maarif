<?php

namespace App\Domain\Bank\Parsers\Contracts;

interface BankStatementParserInterface
{
    /**
     * Parse the bank statement file into raw structured row arrays.
     *
     * @param string $filePath
     * @param array $options Optional settings (e.g. sheet_name, account_number, mapping_version)
     * @return array<int, array{
     *     source_file: string,
     *     source_sheet: ?string,
     *     source_row: int,
     *     raw_cells: array<string, mixed>,
     *     date_raw: mixed,
     *     desc_raw: mixed,
     *     debit_raw: mixed,
     *     credit_raw: mixed,
     *     balance_raw: mixed,
     *     ref_raw: mixed
     * }>
     */
    public function parse(string $filePath, array $options = []): array;
}
