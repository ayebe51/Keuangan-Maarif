<?php

namespace App\Domain\Classification\Contracts;

interface LegacyCodeResolverInterface
{
    /**
     * Attempt to extract and resolve legacy accounting code from description or text.
     *
     * @return array{legacy_code: string, account_id: int, transaction_type: ?string}|null
     */
    public function resolve(int $organizationId, string $description): ?array;
}
