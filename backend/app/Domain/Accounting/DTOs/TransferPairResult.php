<?php

namespace App\Domain\Accounting\DTOs;

use App\Domain\Accounting\Enums\TransferPairingStatus;

class TransferPairResult
{
    /**
     * @param  array<int>  $candidateIds
     */
    public function __construct(
        public readonly TransferPairingStatus $status,
        public readonly ?int $sourceTransactionId = null,
        public readonly ?int $destinationTransactionId = null,
        public readonly bool $matched = false,
        public readonly string $reasonCode = 'UNKNOWN',
        public readonly string $message = '',
        public readonly array $candidateIds = [],
        public readonly array $context = []
    ) {}

    public static function paired(
        int $sourceId,
        int $destId,
        string $message = 'Internal transfer successfully paired (2:1 cardinality established).',
        array $context = []
    ): self {
        return new self(
            status: TransferPairingStatus::PAIRED,
            sourceTransactionId: $sourceId,
            destinationTransactionId: $destId,
            matched: true,
            reasonCode: 'PAIRED',
            message: $message,
            candidateIds: [$destId],
            context: $context
        );
    }

    public static function pending(
        int $transactionId,
        string $reasonCode = 'NO_OPPOSITE_LEG_FOUND',
        string $message = 'No matching opposite transfer leg found. Waiting in pending queue.',
        array $context = []
    ): self {
        return new self(
            status: TransferPairingStatus::PENDING_PAIRING,
            sourceTransactionId: $transactionId,
            matched: false,
            reasonCode: $reasonCode,
            message: $message,
            candidateIds: [],
            context: $context
        );
    }

    /**
     * @param  array<int>  $candidateIds
     */
    public static function ambiguous(
        int $transactionId,
        array $candidateIds,
        string $message = 'Multiple candidate legs match amount and date window. Manual resolution required.',
        array $context = []
    ): self {
        return new self(
            status: TransferPairingStatus::PAIRING_AMBIGUOUS,
            sourceTransactionId: $transactionId,
            matched: false,
            reasonCode: 'PAIRING_AMBIGUOUS',
            message: $message,
            candidateIds: $candidateIds,
            context: $context
        );
    }

    public static function failed(
        int $transactionId,
        string $reasonCode,
        string $message,
        array $context = []
    ): self {
        return new self(
            status: TransferPairingStatus::PAIRING_FAILED,
            sourceTransactionId: $transactionId,
            matched: false,
            reasonCode: $reasonCode,
            message: $message,
            candidateIds: [],
            context: $context
        );
    }

    public static function notApplicable(
        int $transactionId,
        string $message = 'Transaction is not an internal bank transfer.'
    ): self {
        return new self(
            status: TransferPairingStatus::NOT_APPLICABLE,
            sourceTransactionId: $transactionId,
            matched: false,
            reasonCode: 'NOT_APPLICABLE',
            message: $message,
            candidateIds: [],
            context: []
        );
    }

    public function isPaired(): bool
    {
        return $this->status === TransferPairingStatus::PAIRED;
    }

    public function isSuccessful(): bool
    {
        return $this->matched;
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'source_transaction_id' => $this->sourceTransactionId,
            'destination_transaction_id' => $this->destinationTransactionId,
            'matched' => $this->matched,
            'reason_code' => $this->reasonCode,
            'message' => $this->message,
            'candidate_ids' => $this->candidateIds,
            'context' => $this->context,
        ];
    }
}
