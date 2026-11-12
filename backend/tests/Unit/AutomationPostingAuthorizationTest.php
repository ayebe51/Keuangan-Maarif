<?php

namespace Tests\Unit;

use App\Domain\Accounting\DTOs\AutomationPostingAuthorization;
use App\Domain\Accounting\Exceptions\InvalidAutomationAuthorizationException;
use PHPUnit\Framework\TestCase;

class AutomationPostingAuthorizationTest extends TestCase
{
    public function test_valid_authorization_context(): void
    {
        $auth = new AutomationPostingAuthorization(
            organizationId: 1,
            journalEntryId: 10,
            businessTransactionId: 20,
            classificationRuleId: 5,
            expectedRuleVersion: 2,
            expectedRuleSnapshotHash: 'hash123',
            context: ['trigger' => 'automated_posting']
        );

        $this->assertSame(1, $auth->getOrganizationId());
        $this->assertSame(10, $auth->getJournalEntryId());
        $this->assertSame(20, $auth->getBusinessTransactionId());
        $this->assertSame(5, $auth->getClassificationRuleId());
        $this->assertSame(2, $auth->getExpectedRuleVersion());
        $this->assertSame('hash123', $auth->getExpectedRuleSnapshotHash());
        $this->assertSame(['trigger' => 'automated_posting'], $auth->getContext());
    }

    public function test_rejects_non_positive_organization_id(): void
    {
        $this->expectException(InvalidAutomationAuthorizationException::class);
        $this->expectExceptionMessage('Organization ID must be positive.');

        new AutomationPostingAuthorization(
            organizationId: 0,
            journalEntryId: 10,
            businessTransactionId: 20,
            classificationRuleId: 5,
            expectedRuleVersion: 1
        );
    }

    public function test_rejects_non_positive_journal_entry_id(): void
    {
        $this->expectException(InvalidAutomationAuthorizationException::class);
        $this->expectExceptionMessage('Journal Entry ID must be positive.');

        new AutomationPostingAuthorization(
            organizationId: 1,
            journalEntryId: -1,
            businessTransactionId: 20,
            classificationRuleId: 5,
            expectedRuleVersion: 1
        );
    }

    public function test_rejects_non_positive_business_transaction_id(): void
    {
        $this->expectException(InvalidAutomationAuthorizationException::class);
        $this->expectExceptionMessage('Business Transaction ID must be positive.');

        new AutomationPostingAuthorization(
            organizationId: 1,
            journalEntryId: 10,
            businessTransactionId: 0,
            classificationRuleId: 5,
            expectedRuleVersion: 1
        );
    }

    public function test_rejects_non_positive_classification_rule_id(): void
    {
        $this->expectException(InvalidAutomationAuthorizationException::class);
        $this->expectExceptionMessage('Classification Rule ID must be positive.');

        new AutomationPostingAuthorization(
            organizationId: 1,
            journalEntryId: 10,
            businessTransactionId: 20,
            classificationRuleId: 0,
            expectedRuleVersion: 1
        );
    }

    public function test_rejects_non_positive_expected_rule_version(): void
    {
        $this->expectException(InvalidAutomationAuthorizationException::class);
        $this->expectExceptionMessage('Expected rule version must be positive.');

        new AutomationPostingAuthorization(
            organizationId: 1,
            journalEntryId: 10,
            businessTransactionId: 20,
            classificationRuleId: 5,
            expectedRuleVersion: 0
        );
    }

    public function test_snapshot_hash_is_deterministic_and_order_independent(): void
    {
        $snapshot1 = [
            'rule_id' => 10,
            'version' => 1,
            'pattern' => 'BUNGA',
            'amount' => '100000.00',
        ];

        $snapshot2 = [
            'amount' => '100000.00',
            'pattern' => 'BUNGA',
            'version' => 1,
            'rule_id' => 10,
        ];

        $hash1 = AutomationPostingAuthorization::computeSnapshotHash($snapshot1);
        $hash2 = AutomationPostingAuthorization::computeSnapshotHash($snapshot2);

        $this->assertSame($hash1, $hash2);
        $this->assertNotEmpty($hash1);
    }

    public function test_snapshot_hash_changes_when_values_are_tampered(): void
    {
        $snapshot1 = [
            'rule_id' => 10,
            'version' => 1,
            'max_amount' => '5000000.00',
        ];

        $snapshot2 = [
            'rule_id' => 10,
            'version' => 1,
            'max_amount' => '9999999.00', // Tampered
        ];

        $hash1 = AutomationPostingAuthorization::computeSnapshotHash($snapshot1);
        $hash2 = AutomationPostingAuthorization::computeSnapshotHash($snapshot2);

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_to_array_contains_all_fields(): void
    {
        $auth = new AutomationPostingAuthorization(
            organizationId: 1,
            journalEntryId: 10,
            businessTransactionId: 20,
            classificationRuleId: 5,
            expectedRuleVersion: 3,
            expectedRuleSnapshotHash: 'abc',
            context: ['env' => 'testing']
        );

        $array = $auth->toArray();

        $this->assertSame(1, $array['organization_id']);
        $this->assertSame(10, $array['journal_entry_id']);
        $this->assertSame(20, $array['business_transaction_id']);
        $this->assertSame(5, $array['classification_rule_id']);
        $this->assertSame(3, $array['expected_rule_version']);
        $this->assertSame('abc', $array['expected_rule_snapshot_hash']);
        $this->assertSame(['env' => 'testing'], $array['context']);
    }
}
