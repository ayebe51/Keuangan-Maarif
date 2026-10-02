<?php

namespace App\Domain\Program\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Domain\Program\Models\Program;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProgramService
{
    public function create(array $data, ?User $user = null): Program
    {
        return DB::transaction(function () use ($data, $user) {
            $orgId = (int) $data['organization_id'];

            $program = Program::create([
                'organization_id' => $orgId,
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if ($user) {
                AuditService::log(
                    action: 'PROGRAM_CREATED',
                    modelType: Program::class,
                    modelId: $program->id,
                    newValues: $program->toArray(),
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $program;
        });
    }

    public function update(Program $program, array $data, ?User $user = null): Program
    {
        return DB::transaction(function () use ($program, $data, $user) {
            $orgId = (int) $program->organization_id;

            if (array_key_exists('organization_id', $data) && (int) $data['organization_id'] !== $orgId) {
                throw new CrossTenantViolationException("Cannot change organization of program.");
            }

            $before = $program->toArray();
            $program->update($data);

            if ($user) {
                AuditService::log(
                    action: 'PROGRAM_UPDATED',
                    modelType: Program::class,
                    modelId: $program->id,
                    oldValues: $before,
                    newValues: $program->fresh()->toArray(),
                    userId: $user->id,
                    organizationId: $orgId
                );
            }

            return $program;
        });
    }

    public function delete(Program $program, ?User $user = null): void
    {
        DB::transaction(function () use ($program, $user) {
            $orgId = (int) $program->organization_id;
            $before = $program->toArray();

            $program->delete();

            if ($user) {
                AuditService::log(
                    action: 'PROGRAM_DELETED',
                    modelType: Program::class,
                    modelId: $program->id,
                    oldValues: $before,
                    userId: $user->id,
                    organizationId: $orgId
                );
            }
        });
    }
}
