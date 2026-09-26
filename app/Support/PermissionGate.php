<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Schema\Operation;
use App\Schema\SchemaCache;

/**
 * Answers whether an operation is allowed in a team from the permissions /me/teams reported. When they
 * are unknown (an older API, no cache yet) it allows, and the server's own 403 gets the same message.
 */
class PermissionGate
{
    /** @param array<string, mixed>|null $team */
    public function allows(Operation $operation, ?array $team): bool
    {
        if ($operation->permission === null || ! is_array($team['permissions'] ?? null)) {
            return true;
        }

        return in_array($operation->permission, $team['permissions'], true);
    }

    /** @param array<string, mixed>|null $team */
    public function ensure(Operation $operation, ?array $team): void
    {
        if (! $this->allows($operation, $team)) {
            throw $this->denial((string) $operation->permission, $team['name'] ?? null, $operation->permissionLabel);
        }
    }

    public function denial(string $permission, ?string $teamName, ?string $label = null): ApiException
    {
        $label ??= app(SchemaCache::class)->permissionLabel($permission);
        $where = $teamName === null ? '' : " in team {$teamName}";
        $requires = $label === null ? $permission : "{$label} ({$permission})";

        return new ApiException("No access to {$permission}{$where}. Requires: {$requires}.", 403, $permission);
    }
}
