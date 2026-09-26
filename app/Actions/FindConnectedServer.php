<?php

namespace App\Actions;

use App\Exceptions\ApiException;
use App\Exceptions\StepException;
use App\Schema\SchemaCache;
use App\Support\PermissionGate;
use App\Support\RecordFinder;
use App\Support\Servers;
use Lorisleiva\Actions\Concerns\AsAction;

class FindConnectedServer
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $team
     * @param  array<string, mixed>  $environment
     */
    public function handle(array $team, array $environment): string
    {
        $list = app(SchemaCache::class)->findByRoute('api.team.environments.servers.index')
            ?? throw new StepException('This version of the API cannot list an environment\'s servers. Run `rocket api:refresh`.');

        app(PermissionGate::class)->ensure($list, $team);

        $server = collect(app(RecordFinder::class)->all($list, ['team' => (string) $team['slug'], 'environment' => (string) $environment['id']]))
            ->first(fn (array $server): bool => ($server['is_connected'] ?? false) === true && Servers::host($server) !== null);

        if ($server === null) {
            throw new ApiException("{$environment['name']} has no connected server.", 404);
        }

        return (string) Servers::host($server);
    }
}
