<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class InvalidTransferPairingException extends RuntimeException
{
    public function __construct(
        public readonly int $sourceTransactionId,
        public readonly ?int $destinationTransactionId = null,
        string $message = 'Invalid transfer pairing attempt.'
    ) {
        parent::__construct($message, 422);
    }
}
