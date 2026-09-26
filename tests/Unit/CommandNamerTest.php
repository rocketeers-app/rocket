<?php

use App\Schema\CommandNamer;
use App\Schema\SchemaCache;

it('names commands after their route', function (string $route, string $envelope, string $command): void {
    expect(CommandNamer::name($route, $envelope))->toBe($command);
})->with([
    ['api.team.environments.index', 'paginated', 'environments:list'],
    ['api.team.environments.show', 'single', 'environments:read'],
    ['api.team.environments.store', 'none', 'environments:create'],
    ['api.team.environments.destroy', 'none', 'environments:delete'],
    ['api.team.environments.deploy', 'none', 'environments:deploy'],
    ['api.team.environments.daemons.index', 'paginated', 'environments:daemons:list'],
    ['api.team.servers.daemons.restart', 'none', 'servers:daemons:restart'],
    ['api.team.daemons', 'paginated', 'daemons:list'],
    ['api.team.finances', 'custom', 'finances'],
]);

it('gives every operation in the schema its own command, clear of the hand-written ones', function (): void {
    $operations = app(SchemaCache::class)->operations();
    $commands = array_map(fn ($operation) => $operation->command, array_values($operations));

    expect($operations)->toHaveCount(283)
        ->and(array_unique($commands))->toHaveCount(count($commands))
        ->and($commands)->not->toContain('me', 'ssh:config', 'setup-token', 'team', 'teams', 'hub', 'api:refresh', 'list', 'help');
});
