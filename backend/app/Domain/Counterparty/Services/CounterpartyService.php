<?php

namespace App\Domain\Counterparty\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Counterparty\Models\Counterparty;
use App\Domain\Counterparty\Models\CounterpartyAlias;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CounterpartyService
{
    public function create(array $data, ?User $user = null): Counterparty
    {
        return DB::transaction(function () use ($data, $user) {
            $orgId = (int) $data['organization_id'];

            if (!in_array($data['role'], Counterparty::ALL_ROLES, true)) {
                throw new InvalidArgumentException("Invalid counterparty role '{$data['role']}'.");
            }

            $counterparty = Counterparty::create([
                'organization_id' => $orgId,
                'role' => $data['role'],
                'code' => $data['code'] ?? null,
                'name' => $data['name'],
                'npwp' => $data['npwp'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            // Add the name itself as a default alias
            $this->addAlias(
                $counterparty,
                $counterparty->name,
                CounterpartyAlias::SOURCE_SYSTEM,
                $user
            );

            // Add additional aliases if provided
            if (!empty($data['aliases']) && is_array($data['aliases'])) {
                foreach ($data['aliases'] as $alias) {
                    if (is_string($alias) && trim($alias) !== '') {
                        $this->addAlias($counterparty, $alias, CounterpartyAlias::SOURCE_MANUAL, $user);
