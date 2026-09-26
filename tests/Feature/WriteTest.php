<?php

use Saloon\Http\Faking\MockResponse;

beforeEach(fn () => actingInTeam());

function paginated(array $records): MockResponse
{
    return MockResponse::make(['data' => $records, 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($records)]]);
}

it('names the options a script still has to pass', function (): void {
    $mock = fakeApi([]);

    [$code, $output] = runCommand('environments:daemons:create', ['environment' => '9f1c2e4a-0000-4000-8000-000000000001', '--name' => 'horizon', '--no-interaction' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('Missing required fields', '--command=', '--username=', '--processes=')
        ->not->toContain('--environment=');

    $mock->assertNothingSent();
});

it('sends a write built from options, resolving a relation by name', function (): void {
    $environment = environmentRecord();
    $server = serverRecord();

    $mock = fakeApi([
        'api.team.environments.index' => paginated([$environment]),
        'api.team.servers.index' => paginated([$server]),
        'api.team.daemons.store' => MockResponse::make(['data' => ['id' => 12, 'name' => 'horizon', 'status' => 'installing']], 202),
    ]);

    [$code, $output] = runCommand('daemons:create', [
        '--name' => 'horizon', '--command' => 'php artisan horizon', '--username' => 'rocketeer', '--processes' => '2',
        '--environment' => 'acme-production', '--server' => 'web-1', '--force' => true,
    ]);

    expect($code)->toBe(0)->and($output)->toContain('✓ Queued: Create Daemon');

    $mock->assertSent(fn ($request, $response) => $request->operation->name === 'api.team.daemons.store'
        && $request->payload === [
            'name' => 'horizon', 'command' => 'php artisan horizon', 'username' => 'rocketeer', 'processes' => 2,
            'environment_id' => $environment['id'], 'server_id' => $server['id'],
        ]);
});

it('rejects an option that does not fit its field', function (): void {
    fakeApi([]);

    [$code, $output] = runCommand('environments:daemons:create', [
        'environment' => '9f1c2e4a-0000-4000-8000-000000000001', '--name' => 'x', '--command' => 'y', '--username' => 'z', '--processes' => 'two', '--json' => true,
    ]);

    expect($code)->toBe(1)->and(json_decode($output, true)['error']['errors'])->toBe(['processes' => ['processes must be a whole number.']]);
});

it('asks for what is missing, picks a relation from a search and asks again after a 422', function (): void {
    $calls = 0;

    $mock = fakeApi([
        'api.team.environments.index' => paginated([environmentRecord()]),
        'api.team.daemons.store' => function () use (&$calls): MockResponse {
            return ++$calls === 1
                ? MockResponse::make(['message' => 'The processes field must be at least 1.', 'errors' => ['processes' => ['The processes field must be at least 1.']]], 422)
                : MockResponse::make(['data' => ['id' => 12, 'name' => 'horizon']], 202);
        },
    ]);

    $this->artisan('daemons:create', ['--command' => 'php artisan horizon', '--username' => 'rocketeer', '--processes' => '0'])
        ->expectsQuestion('Name', 'horizon')
        ->expectsQuestion('Environment', 'acme')
        ->expectsChoice('Environment', 'id:'.environmentRecord()['id'], ['id:'.environmentRecord()['id'] => 'Acme production  (acme-production)'])
        ->expectsChoice('Set optional fields?', [], ['directory' => 'Directory', 'server_id' => 'Server'])
        ->expectsConfirmation('Send this?', 'yes')
        ->expectsQuestion('Processes', '2')
        ->expectsOutputToContain('✓ Queued: Create Daemon')
        ->assertSuccessful();

    expect($calls)->toBe(2);

    $mock->assertSent(fn ($request) => $request->operation->name === 'api.team.daemons.store' && ($request->payload['processes'] ?? null) === 2 && $request->payload['environment_id'] === environmentRecord()['id']);
});
