<?php

namespace App\Domain\Bank\Parsers\Contracts;

interface StatementMappingProfileInterface
{
    /**
     * Unique profile identifier (e.g. bri_giro_v1, bri_tabungan_v1, buku_kas_v1, generic_csv_v1)
     */
    public function name(): string;

    /**
     * Check if given raw header row matches this profile.
     */
    public function matchHeaders(array $headers): bool;

    /**
     * Map a raw row into standardized keys: date, description, debit, credit, balance, reference.
     *
     * @param array $row
     * @param array $headers
     * @return array{
     *     date: mixed,
     *     description: mixed,
     *     debit: mixed,
     *     credit: mixed,
     *     balance: mixed,
     *     reference: mixed
     * }
     */
    public function mapRow(array $row, array $headers = []): array;
}
