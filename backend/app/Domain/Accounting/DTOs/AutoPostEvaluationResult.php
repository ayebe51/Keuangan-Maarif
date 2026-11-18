<?php

namespace App\Domain\Accounting\DTOs;

use App\Domain\Accounting\Enums\AutoPostDecisionOutcome;

final class AutoPostEvaluationResult
{
    public function __construct(
        public readonly AutoPostDecisionOutcome $outcome,
        public readonly string $reasonCode,
        public readonly string $message,
        public readonly int $businessTransactionId,
        public readonly ?int $classificationRuleId = null,
        public readonly ?int $journalEntryId = null,
        public readonly array $context = []
    ) {}

    public function isEligible(): bool
    {
        return $this->outcome === AutoPostDecisionOutcome::ELIGIBLE;
    }

    public static function eligible(
        int $businessTransactionId,
        int $classificationRuleId,
        ?int $journalEntryId = null,
        string $message = 'Transaction is eligible for automated system posting.',
        array $context = []
    ): self {
        return new self(
            outcome: AutoPostDecisionOutcome::ELIGIBLE,
            reasonCode: 'ELIGIBLE',
            message: $message,
            businessTransactionId: $businessTransactionId,
            classificationRuleId: $classificationRuleId,
            journalEntryId: $journalEntryId,
            context: $context
        );
    }

    public static function blocked(
        int $businessTransactionId,
        string $reasonCode,
        string $message,
        ?int $classificationRuleId = null,
        array $context = []
    ): self {
        return new self(
            outcome: AutoPostDecisionOutcome::BLOCKED,
            reasonCode: $reasonCode,
            message: $message,
            businessTransactionId: $businessTransactionId,
            classificationRuleId: $classificationRuleId,
            context: $context
        );
    }

    public static function requiresHumanReview(
        int $businessTransactionId,
        string $reasonCode,
        string $message,
        ?int $classificationRuleId = null,
        array $context = []
    ): self {
        return new self(
            outcome: AutoPostDecisionOutcome::REQUIRES_HUMAN_REVIEW,
            reasonCode: $reasonCode,
            message: $message,
            businessTransactionId: $businessTransactionId,
            classificationRuleId: $classificationRuleId,
            context: $context
        );
    }

    public static function alreadyPosted(
        int $businessTransactionId,
        ?int $journalEntryId = null,
        string $message = 'Transaction has already been posted to the ledger.',
        array $context = []
    ): self {
        return new self(
            outcome: AutoPostDecisionOutcome::ALREADY_POSTED,
            reasonCode: 'ALREADY_POSTED',
            message: $message,
            businessTransactionId: $businessTransactionId,
            journalEntryId: $journalEntryId,
            context: $context
        );
    }

    public static function permanentFailure(
        int $businessTransactionId,
        string $reasonCode,
        string $message,
        ?int $classificationRuleId = null,
        array $context = []
    ): self {
        return new self(
            outcome: AutoPostDecisionOutcome::PERMANENT_FAILURE,
            reasonCode: $reasonCode,
            message: $message,
            businessTransactionId: $businessTransactionId,
            classificationRuleId: $classificationRuleId,
            context: $context
        );
    }

    public static function retryableFailure(
        int $businessTransactionId,
        string $reasonCode,
        string $message,
        ?int $classificationRuleId = null,
        array $context = []
    ): self {
        return new self(
            outcome: AutoPostDecisionOutcome::RETRYABLE_FAILURE,
            reasonCode: $reasonCode,
            message: $message,
            businessTransactionId: $businessTransactionId,
            classificationRuleId: $classificationRuleId,
            context: $context
        );
    }

    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'is_eligible' => $this->isEligible(),
            'reason_code' => $this->reasonCode,
            'message' => $this->message,
            'business_transaction_id' => $this->businessTransactionId,
            'classification_rule_id' => $this->classificationRuleId,
            'journal_entry_id' => $this->journalEntryId,
            'context' => $this->context,
        ];
    }
}
