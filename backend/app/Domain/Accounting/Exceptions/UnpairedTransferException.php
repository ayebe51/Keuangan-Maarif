<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class UnpairedTransferException extends RuntimeException
{
    public function __construct(
        public readonly int $businessTransactionId,
        public readonly string $pairingStatus,
        string $message = ''
    ) {
        $msg = $message ?: "BusinessTransaction #{$businessTransactionId} cannot be posted: transfer pairing status is '{$pairingStatus}' (must be PAIRED).";
        parent::__construct($msg, 422);
    }
}
