<?php

namespace App\Actions;

use App\Exceptions\ApiException;
use App\Exceptions\StepException;
use App\Schema\SchemaCache;
use App\Support\PermissionGate;
use App\Support\RecordFinder;
use App\Support\Servers;
use Lorisleiva\Actions\Concerns\AsAction;

class ListEnvironmentHosts
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $team
     * @param  array<string, mixed>  $environment
     * @return array<int, string>
     */
    public function handle(array $team, array $environment): array
    {
        $list = app(SchemaCache::class)->findByRoute('api.team.environments.servers.index')
            ?? throw new StepException('This version of the API cannot list an environment\'s servers. Run `rocket api:refresh`.');

        app(PermissionGate::class)->ensure($list, $team);

        $hosts = collect(app(RecordFinder::class)->all($list, ['team' => (string) $team['slug'], 'environment' => (string) $environment['id']]))
            ->sortByDesc(fn (array $server): bool => (bool) ($server['is_web'] ?? false))
            ->map(fn (array $server): ?string => Servers::host($server))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($hosts === []) {
            throw new ApiException("{$environment['name']} runs on no server yet.", 404);
        }

        return $hosts;
    }
}
