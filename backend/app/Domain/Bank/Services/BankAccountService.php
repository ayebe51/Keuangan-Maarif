<?php

namespace App\Domain\Bank\Services;

use App\Domain\Accounting\Exceptions\OpeningBalanceConfigurationException;
use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Accounting\Services\AccountService;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BankAccountService
{
    public function __construct(
        protected AccountService $accountService
    ) {}

    public function create(array $data, ?User $user = null): BankAccount
    {
        return DB::transaction(function () use ($data, $user) {
            $orgId = (int) $data['organization_id'];

            // Invariant guard against opening_balance
            if (array_key_exists('opening_balance', $data)) {
                throw new OpeningBalanceConfigurationException(
                    "Opening balance cannot be set directly on BankAccount. Opening balance MUST flow through OpeningBalanceSource -> JournalEntry -> JournalLine."
                );
            }

            // Validate COA account if provided
            $accountId = $data['account_id'] ?? null;
            if ($accountId) {
                $account = $this->accountService->ensurePostable($accountId, $orgId);
            }

            $bankAccount = BankAccount::create([
                'organization_id' => $orgId,
                'bank_name' => $data['bank_name'],
                'account_number' => $data['account_number'],
                'account_name' => $data['account_name'],
                'branch' => $data['branch'] ?? null,
                'currency' => $data['currency'] ?? 'IDR',
                'account_id' => $accountId,
                'type' => $data['type'] ?? BankAccount::TYPE_CURRENT,
                'is_active' => $data['is_active'] ?? true,
            ]);

            // Sync account mapping if account_id is set
            if ($accountId) {
                AccountMapping::updateOrCreate(
                    [
                        'organization_id' => $orgId,
                        'mapping_type' => AccountMapping::TYPE_BANK_ACCOUNT,
                        'mapping_key' => (string) $bankAccount->id,
                    ],
                    [
                        'account_id' => $accountId,
                        'is_active' => true,
                    ]
                );
            }

            if ($user) {
                AuditService::log(
                    action: 'BANK_ACCOUNT_CREATED',
                    modelType: BankAccount::class,
                    modelId: $bankAccount->id,
                    newValues: $bankAccount->toArray(),
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $bankAccount;
        });
    }

    public function update(BankAccount $bankAccount, array $data, ?User $user = null): BankAccount
    {
        return DB::transaction(function () use ($bankAccount, $data, $user) {
            $orgId = (int) $bankAccount->organization_id;

            if (array_key_exists('organization_id', $data) && (int) $data['organization_id'] !== $orgId) {
                throw new CrossTenantViolationException("Cannot change organization of bank account.");
            }

            if (array_key_exists('opening_balance', $data)) {
                throw new OpeningBalanceConfigurationException(
                    "Opening balance cannot be set directly on BankAccount. Opening balance MUST flow through OpeningBalanceSource -> JournalEntry -> JournalLine."
                );
            }

            if (array_key_exists('account_id', $data) && $data['account_id'] !== null) {
                $this->accountService->ensurePostable($data['account_id'], $orgId);
            }

            $before = $bankAccount->toArray();
            $bankAccount->update($data);

            if (array_key_exists('account_id', $data)) {
                if ($bankAccount->account_id) {
                    AccountMapping::updateOrCreate(
                        [
                            'organization_id' => $orgId,
                            'mapping_type' => AccountMapping::TYPE_BANK_ACCOUNT,
                            'mapping_key' => (string) $bankAccount->id,
                        ],
                        [
                            'account_id' => $bankAccount->account_id,
                            'is_active' => true,
                        ]
                    );
                } else {
                    AccountMapping::where('organization_id', $orgId)
                        ->where('mapping_type', AccountMapping::TYPE_BANK_ACCOUNT)
                        ->where('mapping_key', (string) $bankAccount->id)
                        ->delete();
                }
            }

            if ($user) {
                AuditService::log(
                    action: 'BANK_ACCOUNT_UPDATED',
                    modelType: BankAccount::class,
                    modelId: $bankAccount->id,
                    oldValues: $before,
                    newValues: $bankAccount->fresh()->toArray(),
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $bankAccount;
        });
    }

    public function delete(BankAccount $bankAccount, ?User $user = null): void
    {
        DB::transaction(function () use ($bankAccount, $user) {
            $orgId = (int) $bankAccount->organization_id;
            $before = $bankAccount->toArray();

            // Soft delete
            $bankAccount->delete();

            if ($user) {
                AuditService::log(
                    action: 'BANK_ACCOUNT_DELETED',
                    modelType: BankAccount::class,
                    modelId: $bankAccount->id,
                    oldValues: $before,
                    userId: $user->id,
                    organizationId: $orgId
                );
            }
        });
    }
}
