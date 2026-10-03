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
                    }
                }
            }

            if ($user) {
                AuditService::log(
                    action: 'COUNTERPARTY_CREATED',
                    modelType: Counterparty::class,
                    modelId: $counterparty->id,
                    newValues: $counterparty->toArray(),
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $counterparty;
        });
    }

    public function update(Counterparty $counterparty, array $data, ?User $user = null): Counterparty
    {
        return DB::transaction(function () use ($counterparty, $data, $user) {
            $orgId = (int) $counterparty->organization_id;

            if (array_key_exists('organization_id', $data) && (int) $data['organization_id'] !== $orgId) {
                throw new CrossTenantViolationException("Cannot change organization of counterparty.");
            }

            if (isset($data['role']) && !in_array($data['role'], Counterparty::ALL_ROLES, true)) {
                throw new InvalidArgumentException("Invalid counterparty role '{$data['role']}'.");
            }

            $before = $counterparty->toArray();
            $counterparty->update($data);

            if ($user) {
                AuditService::log(
                    action: 'COUNTERPARTY_UPDATED',
                    modelType: Counterparty::class,
                    modelId: $counterparty->id,
                    oldValues: $before,
                    newValues: $counterparty->fresh()->toArray(),
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $counterparty;
        });
    }

    public function delete(Counterparty $counterparty, ?User $user = null): void
    {
        DB::transaction(function () use ($counterparty, $user) {
            $orgId = (int) $counterparty->organization_id;
            $before = $counterparty->toArray();

            $counterparty->delete();

            if ($user) {
                AuditService::log(
                    action: 'COUNTERPARTY_DELETED',
                    modelType: Counterparty::class,
                    modelId: $counterparty->id,
                    oldValues: $before,
                    userId: $user->id,
                    organizationId: $orgId
                );
            }
        });
    }

    public function addAlias(
        Counterparty $counterparty,
        string $aliasName,
        string $source = CounterpartyAlias::SOURCE_MANUAL,
        ?User $user = null
    ): CounterpartyAlias {
        $orgId = (int) $counterparty->organization_id;
        $normalized = CounterpartyAlias::normalize($aliasName);

        if ($normalized === '') {
            throw new InvalidArgumentException("Alias name cannot be empty.");
        }

        // Avoid duplicate within organization
        $existing = CounterpartyAlias::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->whereRaw('UPPER(alias_name) = ?', [$normalized])
            ->first();

        if ($existing) {
            if ((int) $existing->counterparty_id === (int) $counterparty->id) {
                return $existing;
            }
            throw new InvalidArgumentException("Alias '{$aliasName}' is already registered to another counterparty.");
        }

        $alias = CounterpartyAlias::create([
            'organization_id' => $orgId,
            'counterparty_id' => $counterparty->id,
            'alias_name' => $aliasName,
            'source' => $source,
            'is_active' => true,
        ]);

        if ($user) {
            AuditService::log(
                action: 'COUNTERPARTY_ALIAS_ADDED',
                modelType: CounterpartyAlias::class,
                modelId: $alias->id,
                newValues: $alias->toArray(),
                userId: $user->id,
                organizationId: $orgId
            );
        }

        return $alias;
    }

    public function removeAlias(CounterpartyAlias $alias, ?User $user = null): void
    {
        DB::transaction(function () use ($alias, $user) {
            $orgId = (int) $alias->organization_id;
            $before = $alias->toArray();

            $alias->delete();

            if ($user) {
                AuditService::log(
                    action: 'COUNTERPARTY_ALIAS_REMOVED',
                    modelType: CounterpartyAlias::class,
                    modelId: $alias->id,
                    oldValues: $before,
                    userId: $user->id,
                    organizationId: $orgId
                );
            }
        });
    }

    public function resolve(int $organizationId, string $rawName): ?Counterparty
    {
        $clean = trim($rawName);
        if ($clean === '') {
            return null;
        }

        $normalized = CounterpartyAlias::normalize($clean);

        // 1. Exact case-insensitive match on Counterparty name
        $cp = Counterparty::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereRaw('UPPER(name) = ?', [$normalized])
            ->first();

        if ($cp) {
            return $cp;
        }

        // 2. Exact match on CounterpartyAlias
        $alias = CounterpartyAlias::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereRaw('UPPER(alias_name) = ?', [$normalized])
            ->with('counterparty')
            ->first();

        if ($alias && $alias->counterparty && $alias->counterparty->is_active) {
            return $alias->counterparty;
        }

        // 3. Substring matching: check if an alias is contained within the raw description
        $aliases = CounterpartyAlias::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->with('counterparty')
            ->get();

        foreach ($aliases as $a) {
            $aNorm = CounterpartyAlias::normalize($a->alias_name);
            if (mb_stripos($normalized, $aNorm) !== false && $a->counterparty && $a->counterparty->is_active) {
                return $a->counterparty;
            }
        }

        return null;
    }

    public function getOrCreateSystemBank(int $organizationId, string $bankName = 'Bank BRI'): Counterparty
    {
        $cp = Counterparty::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('role', Counterparty::ROLE_BANK)
            ->where('code', 'BANK-BRI')
            ->first();

        if (!$cp) {
            $cp = Counterparty::create([
                'organization_id' => $organizationId,
                'role' => Counterparty::ROLE_BANK,
                'code' => 'BANK-BRI',
                'name' => $bankName,
                'is_active' => true,
            ]);

            $aliases = [
                'Bank BRI',
                'BRI',
                'Pajak Bulanan (Bank)',
                'Adm Bank',
                'Bunga Rekening (Bank)',
                'BIAYA ADM',
                'PAJAK BUNGA',
            ];

            foreach ($aliases as $alias) {
                try {
                    $this->addAlias($cp, $alias, CounterpartyAlias::SOURCE_SYSTEM);
                } catch (\Exception $e) {
                    // Ignore if already registered
                }
            }
        }

        return $cp;
    }

    public function getOrCreateSystemInternal(int $organizationId, string $name = 'LP Ma\'arif NU'): Counterparty
    {
        $cp = Counterparty::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('role', Counterparty::ROLE_INTERNAL)
            ->where('code', 'INTERNAL-MAARIF')
            ->first();

        if (!$cp) {
            $cp = Counterparty::create([
                'organization_id' => $organizationId,
                'role' => Counterparty::ROLE_INTERNAL,
                'code' => 'INTERNAL-MAARIF',
                'name' => $name,
                'is_active' => true,
            ]);

            try {
                $this->addAlias($cp, $name, CounterpartyAlias::SOURCE_SYSTEM);
                $this->addAlias($cp, 'Internal LP Maarif', CounterpartyAlias::SOURCE_SYSTEM);
            } catch (\Exception $e) {
                // Ignore if already registered
            }
        }

        return $cp;
    }
}
