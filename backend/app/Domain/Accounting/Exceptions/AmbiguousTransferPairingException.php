<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class AmbiguousTransferPairingException extends RuntimeException
{
    /**
     * @param  array<int>  $candidateIds
     */
    public function __construct(
        public readonly int $businessTransactionId,
        public readonly array $candidateIds,
        string $message = ''
    ) {
        $count = count($candidateIds);
        $candidatesStr = implode(', ', $candidateIds);
        $msg = $message ?: "Transfer pairing for transaction #{$businessTransactionId} is ambiguous: found {$count} candidate matches [{$candidatesStr}]. Routed to Transfer Review Queue.";
        parent::__construct($msg, 422);
    }
}
