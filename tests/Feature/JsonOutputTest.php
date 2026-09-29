<?php

use App\Api\Requests\GetMe;
use App\Api\Requests\GetMyTeams;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Support\Facades\Artisan;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

function jsonOf(string $output): mixed
{
    expect(json_validate($output))->toBeTrue("Not JSON: {$output}");

    return json_decode($output, true);
}

beforeEach(fn () => actingInTeam(['environments:read', 'clients:create']));

it('gives every Rocket command a --json option', function (): void {
    $commands = collect(Artisan::all())->filter(fn ($command) => str_starts_with($command::class, 'App\\'));

    expect($commands->count())->toBeGreaterThan(290);

    $commands->each(fn ($command) => expect($command->getDefinition()->hasOption('json'))->toBeTrue("{$command->getName()} has no --json"));
});

it('prints the raw body of a list', function (): void {
    $body = ['data' => [environmentRecord()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]];
    fakeApi(['api.team.environments.index' => MockResponse::make($body)]);

    [$code, $output] = runCommand('environments:list', ['--json' => true]);

    expect($code)->toBe(0)->and(jsonOf($output))->toBe($body);
});

it('prints a text response as content type and body', function (): void {
    actingInTeam();
    fakeApi([
        'api.team.domains' => MockResponse::make(['data' => [['id' => 'd1', 'fqdn' => 'acme.test']], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
        'api.team.domains.dns.export' => MockResponse::make('acme.test. 300 IN A 10.0.0.1', 200, ['Content-Type' => 'text/plain']),
    ]);

    [$code, $output] = runCommand('domains:dns:export', ['domain' => 'acme.test', '--json' => true]);

    expect($code)->toBe(0)->and(jsonOf($output))->toBe(['content_type' => 'text/plain', 'body' => 'acme.test. 300 IN A 10.0.0.1']);
});

it('reports failures as a JSON error with a non-zero exit code', function (string $command, array $parameters, array $responses, array $error): void {
    fakeApi($responses);

    [$code, $output] = runCommand($command, [...$parameters, '--json' => true]);

    expect($code)->toBe(1)->and(jsonOf($output)['error'])->toMatchArray($error);
})->with([
    'missing permission' => ['servers:reboot', ['server' => 'web-1'], [], ['status' => 403, 'permission' => 'servers:reboot']],
    'validation' => ['clients:create', ['--name' => 'Acme'], [
        'api.team.clients.store' => MockResponse::make(['message' => 'The name has already been taken.', 'errors' => ['name' => ['The name has already been taken.']]], 422),
    ], ['status' => 422, 'errors' => ['name' => ['The name has already been taken.']]]],
    'not found' => ['environments:read', ['environment' => '9f1c2e4a-0000-4000-8000-000000000009'], [
        'api.team.environments.show' => MockResponse::make(['message' => 'Not Found'], 404),
    ], ['status' => 404]],
    'missing field' => ['clients:create', [], [], ['status' => 422, 'errors' => ['name' => ['Required: pass --name=…']]]],
    'connection' => ['environments:list', [], [
        'api.team.environments.index' => fn (PendingRequest $pending) => MockResponse::make()->throw(fn ($pending) => new FatalRequestException(new ConnectException('Connection refused', new PsrRequest('GET', 'https://api.rocketeers.test')), $pending)),
    ], ['message' => 'Could not reach Rocketeers at https://api.rocketeers.test/v1.']],
]);

it('asks for a token before anything else when none is saved', function (): void {
    config(['rocketeers.api_token' => null]);
    $mock = fakeApi([]);

    [$code, $output] = runCommand('environments:list', ['--json' => true]);

    expect($code)->toBe(1)->and(jsonOf($output)['error'])->toMatchArray(['status' => 401])
        ->and(jsonOf($output)['error']['message'])->toContain('rocket setup-token');

    $mock->assertNothingSent();
});

it('answers with a fixed shape for the hand-written commands', function (): void {
    fakeApi([
        GetMe::class => MockResponse::make(['data' => ['id' => 'u1', 'name' => 'Ada', 'email' => 'ada@example.com']]),
        GetMyTeams::class => MockResponse::make(['data' => [['id' => 'team-1', 'name' => 'Acme', 'slug' => 'acme', 'permissions' => ['servers:read']]]]),
    ]);

    expect(jsonOf(runCommand('me', ['--json' => true])[1]))->toMatchArray(['email' => 'ada@example.com'])
        ->and(jsonOf(runCommand('team', ['--json' => true])[1]))->toBe(['team' => ['id' => 'team-1', 'name' => 'Acme', 'slug' => 'acme'], 'permissions' => ['servers:read']])
        ->and(jsonOf(runCommand('teams', ['--json' => true])[1])['data'])->toHaveCount(1)
        ->and(jsonOf(runCommand('home', ['--json' => true])[1]))->toHaveKeys(['version', 'team', 'resources', 'local', 'account']);
});

it('reports a missing environment as a JSON error instead of asking', function (string $command): void {
    $mock = fakeApi([]);

    [$code, $output] = runCommand($command, ['--json' => true]);

    expect($code)->toBe(1)->and(jsonOf($output)['error'])->toBe([
        'status' => 422,
        'message' => 'Missing the environment.',
        'errors' => ['environment' => ['Pass the environment slug as an argument: rocket <command> <environment>.']],
    ]);

    $mock->assertNothingSent();
})->with(['deploy', 'install', 'db:import', 'env:pull', 'deployments:follow', 'tail', 'sync']);
