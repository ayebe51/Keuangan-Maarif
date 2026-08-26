<?php

namespace App\Domain\Organization\Exceptions;

use RuntimeException;

class CrossTenantViolationException extends RuntimeException
{
    public function __construct(
        string $message = 'Operasi ditolak: Terjadi pelanggaran batas isolasi antar-organisasi (Cross-Tenant Violation).',
        int $code = 403
    ) {
        parent::__construct($message, $code);
    }
}
