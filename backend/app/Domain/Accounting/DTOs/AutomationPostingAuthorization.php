<?php

namespace App\Domain\Accounting\DTOs;

use App\Domain\Accounting\Exceptions\InvalidAutomationAuthorizationException;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Classification\Models\ClassificationRule;

/**
 * Immutable DTO representing a request for automated system posting.
 *
 * CRITICAL SECURITY INVARIANT:
 * This object is a request envelope, NOT proof of authorization by itself.
 * All authorization facts (rule status, permissions, amounts, SoD, dates)
 * MUST be re-verified by the server from the database inside an exclusive lock.
 */
final class AutomationPostingAuthorization
{
    public function __construct(
        protected int $organizationId,
        protected int $journalEntryId,
        protected int $businessTransactionId,
        protected int $classificationRuleId,
        protected int $expectedRuleVersion,
        protected ?string $expectedRuleSnapshotHash = null,
        protected array $context = []
    ) {
        if ($this->organizationId <= 0) {
            throw new InvalidAutomationAuthorizationException('Organization ID must be positive.');
        }
        if ($this->journalEntryId <= 0) {
            throw new InvalidAutomationAuthorizationException('Journal Entry ID must be positive.');
        }
        if ($this->businessTransactionId <= 0) {
            throw new InvalidAutomationAuthorizationException('Business Transaction ID must be positive.');
        }
        if ($this->classificationRuleId <= 0) {
            throw new InvalidAutomationAuthorizationException('Classification Rule ID must be positive.');
        }
        if ($this->expectedRuleVersion <= 0) {
            throw new InvalidAutomationAuthorizationException('Expected rule version must be positive.');
        }
    }

    public static function fromEntities(
        JournalEntry $entry,
        BusinessTransaction $transaction,
        ClassificationRule $rule,
        array $context = []
    ): self {
        $snapshot = $transaction->rule_snapshot ?? $rule->toSnapshot();
        $snapshotHash = self::computeSnapshotHash($snapshot);

        return new self(
            organizationId: (int) $entry->organization_id,
            journalEntryId: (int) $entry->id,
            businessTransactionId: (int) $transaction->id,
            classificationRuleId: (int) $rule->id,
            expectedRuleVersion: (int) ($rule->version ?? 1),
            expectedRuleSnapshotHash: $snapshotHash,
            context: $context
        );
    }

    public static function computeSnapshotHash(array $snapshot): string
    {
        ksort($snapshot);

        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function getOrganizationId(): int
    {
        return $this->organizationId;
    }

    public function getJournalEntryId(): int
    {
        return $this->journalEntryId;
    }

    public function getBusinessTransactionId(): int
    {
        return $this->businessTransactionId;
    }

    public function getClassificationRuleId(): int
    {
        return $this->classificationRuleId;
    }

    public function getExpectedRuleVersion(): int
    {
        return $this->expectedRuleVersion;
    }

    public function getExpectedRuleSnapshotHash(): ?string
    {
        return $this->expectedRuleSnapshotHash;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'journal_entry_id' => $this->journalEntryId,
            'business_transaction_id' => $this->businessTransactionId,
            'classification_rule_id' => $this->classificationRuleId,
            'expected_rule_version' => $this->expectedRuleVersion,
            'expected_rule_snapshot_hash' => $this->expectedRuleSnapshotHash,
            'context' => $this->context,
        ];
    }
}
