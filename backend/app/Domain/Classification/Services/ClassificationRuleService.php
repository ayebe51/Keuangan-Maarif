<?php

namespace App\Domain\Classification\Services;

use App\Domain\Accounting\Services\AccountService;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Classification\Enums\BusinessTransactionType;
use App\Domain\Classification\Exceptions\InvalidClassificationDirectionException;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ClassificationRuleService
{
    public function __construct(
        protected AccountService $accountService
    ) {}

    public function create(array $data, ?User $user = null): ClassificationRule
    {
        return DB::transaction(function () use ($data, $user) {
            $orgId = (int) $data['organization_id'];

            $txType = $data['transaction_type'] ?? '';
            $bizType = BusinessTransactionType::tryFrom($txType);
            if (!$bizType) {
                throw new InvalidArgumentException("Invalid transaction type '{$txType}'.");
            }
