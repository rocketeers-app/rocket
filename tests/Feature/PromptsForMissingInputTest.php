<?php

use App\Actions\ImportServerDatabase;
use App\Actions\PutEnvLocally;
use App\Actions\ReadRemoteEnvFile;
use App\Api\Requests\GetApiDocs;
use App\Api\Requests\GetMe;
use App\Api\Requests\GetMyTeams;
use App\Support\CommandLog;
use App\Support\Teams;
use Illuminate\Support\Facades\Storage;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    actingInTeam(['environments:read', 'deployments:read', 'deployments:create', 'databases:read', 'servers:read']);
    config(['rocketeers.deployment_poll_interval' => 0]);

    $this->requests = [];
});

function promptRecords(array $records): MockResponse
{
    return MockResponse::make(['data' => $records, 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($records)]]);
}

function promptEnvironment(array $overrides = []): array
{
    return environmentRecord(['id' => 'env-1', ...$overrides]);
}

function inTwoTeams(): void
{
    $permissions = ['environments:read', 'deployments:read', 'deployments:create'];

    Storage::put(Teams::PATH, (string) json_encode([
        'token' => hash('sha256', 'test-token'),
        'fetched_at' => time(),
        'teams' => [
            ['id' => 'team-1', 'name' => 'Acme', 'slug' => 'acme', 'permissions' => $permissions],
            ['id' => 'team-2', 'name' => 'Globex', 'slug' => 'globex', 'permissions' => $permissions],
        ],
    ]));
}

function deployingApi(object $test, array $extra = []): void
{
    fakeApi([
        'api.team.environments.index' => promptRecords([promptEnvironment()]),
        'api.team.environments.deploy' => function (PendingRequest $pending) use ($test): MockResponse {
            $test->requests[] = 'deploy:'.$pending->getUrl();

            return MockResponse::make(['data' => ['id' => 'dep-1']], 201);
        },
        'api.team.environments.deployments.steps' => MockResponse::make([
            'data' => [],
            'deployment' => ['id' => 'dep-1', 'completed_at' => '2026-09-29T10:00:00+00:00', 'human_duration' => '12s'],
            'servers' => [],
        ]),
        ...$extra,
    ]);
}

function fakeSsh(string $files): object
{
    $log = new class($files) extends CommandLog
    {
        public array $commands = [];

        public function __construct(private readonly string $files) {}

        public function run(Process $process, bool $showOutput = true, ?callable $callback = null, ?string $label = null): Process
        {
            $this->commands[] = (string) $label;
            $fake = str_contains((string) $label, 'find ') ? new Process(['printf', '%s', $this->files]) : new Process(['true']);
            $fake->run($callback);

            return $fake;
        }
    };

    app()->instance(CommandLog::class, $log);

    return $log;
}

it('asks for the environment when it is left out', function (): void {
    deployingApi($this);

    $this->artisan('deploy', ['--detach' => true])
        ->expectsQuestion('Environment', 'acme')
        ->expectsChoice('Environment', '0:env-1', ['0:env-1' => 'Acme production  (acme-production)'])
        ->expectsOutputToContain('Deploying acme-production.')
        ->assertSuccessful();

    expect($this->requests)->toBe(['deploy:https://api.rocketeers.test/v1/acme/environments/env-1/deploy']);
});

it('asks for the environment again when the slug is not in any team', function (): void {
    deployingApi($this);

    $this->artisan('deploy', ['environment' => 'acme-prod', '--detach' => true])
        ->expectsQuestion('Environment', 'acme')
        ->expectsChoice('Environment', '0:env-1', ['0:env-1' => 'Acme production  (acme-production)'])
        ->expectsOutputToContain('Deploying acme-production.')
        ->assertSuccessful();
});

it('refuses an unknown slug when nobody can answer', function (): void {
    deployingApi($this);

    [$code, $output] = runCommand('deploy', ['environment' => 'acme-prod', '--json' => true]);

    expect($code)->toBe(1)->and(json_decode($output, true)['error'])->toMatchArray(['status' => 404, 'message' => 'No environment `acme-prod` in any of your teams.']);
});

it('offers the environments of every team, naming the team', function (): void {
    inTwoTeams();
    deployingApi($this, [
        'api.team.environments.index' => fn (PendingRequest $pending) => str_contains($pending->getUrl(), '/globex/')
            ? promptRecords([promptEnvironment(['id' => 'env-2', 'name' => 'Globex production', 'slug' => 'globex-production'])])
            : promptRecords([promptEnvironment()]),
    ]);

    $this->artisan('deploy', ['--detach' => true])
        ->expectsQuestion('Environment', 'production')
        ->expectsChoice('Environment', '1:env-2', [
            '0:env-1' => 'Acme production  (acme-production) · team Acme',
            '1:env-2' => 'Globex production  (globex-production) · team Globex',
        ])
        ->expectsOutputToContain('Deploying globex-production.')
        ->assertSuccessful();

    expect($this->requests)->toBe(['deploy:https://api.rocketeers.test/v1/globex/environments/env-2/deploy']);
});

it('only offers the environments of --team', function (): void {
    inTwoTeams();
    deployingApi($this, [
        'api.team.environments.index' => fn (PendingRequest $pending) => str_contains($pending->getUrl(), '/globex/')
            ? promptRecords([promptEnvironment(['id' => 'env-2', 'name' => 'Globex production', 'slug' => 'globex-production'])])
            : promptRecords([promptEnvironment()]),
    ]);

    $this->artisan('deploy', ['--detach' => true, '--team' => 'Globex'])
        ->expectsQuestion('Environment', 'production')
        ->expectsChoice('Environment', '0:env-2', ['0:env-2' => 'Globex production  (globex-production)'])
        ->assertSuccessful();
});

it('asks for the team when --team is not one of yours, without saving it as the default', function (): void {
    inTwoTeams();
    deployingApi($this, [
        GetMyTeams::class => MockResponse::make(['data' => [
            ['id' => 'team-1', 'name' => 'Acme', 'slug' => 'acme', 'permissions' => ['environments:read', 'deployments:create']],
            ['id' => 'team-2', 'name' => 'Globex', 'slug' => 'globex', 'permissions' => ['environments:read', 'deployments:create']],
        ]]),
    ]);

    $this->artisan('deploy', ['environment' => 'acme-production', '--detach' => true, '--team' => 'typo'])
        ->expectsOutputToContain('Team `typo` is not one of your teams.')
        ->expectsChoice('Which team do you want to work in?', 'acme', ['acme' => 'Acme', 'globex' => 'Globex'])
        ->assertSuccessful();

    expect(config('rocketeers.default_team'))->toBe('acme')
        ->and(Storage::get('.env'))->toContain('DEFAULT_TEAM=acme');
});

it('refuses an unknown --team when nobody can answer', function (): void {
    deployingApi($this, [GetMyTeams::class => MockResponse::make(['data' => [['id' => 'team-1', 'name' => 'Acme', 'slug' => 'acme']]])]);

    [$code, $output] = runCommand('environments:list', ['--team' => 'typo', '--json' => true]);

    expect($code)->toBe(1)->and(json_decode($output, true)['error']['message'])->toBe('Team `typo` is not one of your teams. Run `rocket team` to pick one.');
});

it('asks for the team when the one passed to `team` is not one of yours', function (): void {
    fakeApi([GetMyTeams::class => MockResponse::make(['data' => [
        ['id' => 'team-1', 'name' => 'Acme', 'slug' => 'acme'],
        ['id' => 'team-2', 'name' => 'Globex', 'slug' => 'globex'],
    ]])]);

    $this->artisan('team', ['team' => 'typo'])
        ->expectsOutputToContain('Team `typo` is not one of your teams.')
        ->expectsChoice('Which team do you want to work in?', 'globex', ['acme' => 'Acme', 'globex' => 'Globex'])
        ->assertSuccessful();

    expect(config('rocketeers.default_team'))->toBe('globex');
});

it('asks for a token when none is saved, saves it and goes on', function (): void {
    config(['rocketeers.api_token' => null]);
    Storage::delete(Teams::PATH);

    $mock = fakeApi([
        GetMe::class => MockResponse::make(['data' => ['id' => 'u1', 'name' => 'Ada', 'email' => 'ada@example.com']]),
        GetMyTeams::class => MockResponse::make(['data' => [['id' => 'team-1', 'name' => 'Acme', 'slug' => 'acme', 'permissions' => ['environments:read']]]]),
        GetApiDocs::class => MockResponse::make([], 500),
        'api.team.environments.index' => promptRecords([promptEnvironment()]),
    ]);

    $this->artisan('environments:list')
        ->expectsQuestion('Your Rocket CLI token', 'new-token')
        ->expectsOutputToContain('Acme production')
        ->assertSuccessful();

    expect(config('rocketeers.api_token'))->toBe('new-token')
        ->and(Storage::get('.env'))->toContain('API_TOKEN=new-token');

    $mock->assertSent(fn ($request, $response) => $request instanceof GetMe && $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer new-token');
});

it('asks for the token when setup-token gets none', function (): void {
    fakeApi([
        GetMe::class => MockResponse::make(['data' => ['id' => 'u1', 'name' => 'Ada', 'email' => 'ada@example.com']]),
        GetMyTeams::class => MockResponse::make(['data' => [['id' => 'team-1', 'name' => 'Acme', 'slug' => 'acme']]]),
        GetApiDocs::class => MockResponse::make([], 500),
    ]);

    $this->artisan('setup-token')
        ->expectsQuestion('Your Rocket CLI token', 'new-token')
        ->expectsOutputToContain('Authenticated as Ada (ada@example.com).')
        ->assertSuccessful();

    expect(Storage::get('.env'))->toContain('API_TOKEN=new-token');
});

it('refuses setup-token without a token when nobody can answer', function (): void {
    $mock = fakeApi([]);

    [$code, $output] = runCommand('setup-token', ['--json' => true]);

    expect($code)->toBe(1)->and(json_decode($output, true)['error'])->toMatchArray(['status' => 422, 'message' => 'Pass the token: rocket setup-token <token>.']);

    $mock->assertNothingSent();
});

it('picks the environment for env:pull', function (): void {
    fakeApi([
        'api.team.environments.index' => promptRecords([promptEnvironment()]),
        'api.team.environments.servers.index' => promptRecords([serverRecord(['ip' => '10.0.0.2', 'is_connected' => true])]),
    ]);

    $this->mock(ReadRemoteEnvFile::class)->shouldReceive('handle')->once()->with('10.0.0.2', 'acme-production', null)
        ->andReturn(['contents' => "APP_ENV=production\n", 'wordpress' => false]);
    $this->mock(PutEnvLocally::class)->shouldReceive('handle')->once()->andReturn(getcwd().'/.env');

    $this->artisan('env:pull')
        ->expectsQuestion('Environment', 'acme')
        ->expectsChoice('Environment', '0:env-1', ['0:env-1' => 'Acme production  (acme-production)'])
        ->assertSuccessful();
});

it('picks the environment for db:import', function (): void {
    fakeApi([
        'api.team.environments.index' => promptRecords([promptEnvironment()]),
        'api.team.environments.databases.index' => promptRecords([['id' => 'db-1', 'name' => 'acme', 'driver' => 'mysql_native', 'type' => 'mysql', 'server' => ['name' => 'db-1', 'ip' => '10.0.0.9']]]),
        'api.team.environments.servers.index' => promptRecords([]),
    ]);

    $this->mock(ImportServerDatabase::class)->shouldReceive('handle')->once()
        ->withArgs(fn (array $database, string $environment) => $database['name'] === 'acme' && $environment === 'acme-production');

    $this->artisan('db:import')
        ->expectsQuestion('Environment', 'acme')
        ->expectsChoice('Environment', '0:env-1', ['0:env-1' => 'Acme production  (acme-production)'])
        ->expectsOutputToContain('acme → local acme')
        ->assertSuccessful();
});

it('picks the environment for deployments:follow and follows its latest deployment', function (): void {
    deployingApi($this, ['api.team.environments.deployments.index' => promptRecords([['id' => 'dep-1']])]);

    $this->artisan('deployments:follow')
        ->expectsQuestion('Environment', 'acme')
        ->expectsChoice('Environment', '0:env-1', ['0:env-1' => 'Acme production  (acme-production)'])
        ->expectsOutputToContain('Deployed acme-production in 12s')
        ->assertSuccessful();

    expect($this->requests)->toBe([]);
});

it('offers to deploy when there is no deployment to follow yet', function (): void {
    deployingApi($this, ['api.team.environments.deployments.index' => promptRecords([])]);

    $this->artisan('deployments:follow', ['environment' => 'acme-production'])
        ->expectsConfirmation('acme-production has no deployments yet. Deploy it now?', 'yes')
        ->expectsOutputToContain('Deployed acme-production in 12s')
        ->assertSuccessful();

    expect($this->requests)->toHaveCount(1);
});

it('stops when you do not want to deploy an environment without deployments', function (): void {
    deployingApi($this, ['api.team.environments.deployments.index' => promptRecords([])]);

    $this->artisan('deployments:follow', ['environment' => 'acme-production'])
        ->expectsConfirmation('acme-production has no deployments yet. Deploy it now?', 'no')
        ->expectsOutputToContain('Nothing to follow.')
        ->assertSuccessful();

    expect($this->requests)->toBe([]);
});

it('refuses to follow an environment without deployments when nobody can answer', function (): void {
    deployingApi($this, ['api.team.environments.deployments.index' => promptRecords([])]);

    [$code, $output] = runCommand('deployments:follow', ['environment' => 'acme-production', '--json' => true]);

    expect($code)->toBe(1)
        ->and(json_decode($output, true)['error']['message'])->toBe('acme-production has no deployments yet. Start one with `rocket deploy acme-production`.')
        ->and($this->requests)->toBe([]);
});

it('asks which log file to tail when there are several', function (): void {
    $ssh = fakeSsh("/var/www/acme-production/persistent/storage/logs/laravel.log\n/var/www/acme-production/logs/nginx.log\n");

    $this->artisan('tail', ['site' => 'acme-production'])
        ->expectsChoice('Which log file?', '/var/www/acme-production/logs/nginx.log', [
            '/var/www/acme-production/persistent/storage/logs/laravel.log' => 'laravel.log',
            '/var/www/acme-production/logs/nginx.log' => 'nginx.log',
        ])
        ->assertSuccessful();

    expect($ssh->commands[1])->toContain('tail -f /var/www/acme-production/logs/nginx.log');
});

it('refuses to guess between several log files when nobody can answer', function (): void {
    fakeSsh("/var/www/acme-production/persistent/storage/logs/laravel.log\n/var/www/acme-production/logs/nginx.log\n");

    [$code, $output] = runCommand('tail', ['site' => 'acme-production', '--no-interaction' => true]);

    expect($code)->toBe(1)->and($output)->toContain('Several log files: laravel.log, nginx.log. Pass --file= to pick one.');
});

it('tails the log file named with --file, or the only one', function (?string $file, string $files, string $tailed): void {
    $ssh = fakeSsh($files);

    [$code] = runCommand('tail', ['site' => 'acme-production', '--no-interaction' => true, ...($file === null ? [] : ['--file' => $file])]);

    expect($code)->toBe(0)->and($ssh->commands[1])->toContain("tail -f {$tailed}");
})->with([
    'by name' => ['nginx.log', "/var/www/acme-production/persistent/storage/logs/laravel.log\n/var/www/acme-production/logs/nginx.log\n", '/var/www/acme-production/logs/nginx.log'],
    'by path' => ['/var/www/acme-production/persistent/storage/logs/laravel.log', "/var/www/acme-production/persistent/storage/logs/laravel.log\n/var/www/acme-production/logs/nginx.log\n", '/var/www/acme-production/persistent/storage/logs/laravel.log'],
    'the only one' => [null, "/var/www/acme-production/logs/nginx.log\n", '/var/www/acme-production/logs/nginx.log'],
]);

it('picks the environment to tail when the site is left out', function (): void {
    fakeApi(['api.team.environments.index' => promptRecords([promptEnvironment()])]);
    $ssh = fakeSsh("/var/www/acme-production/logs/nginx.log\n");

    $this->artisan('tail')
        ->expectsQuestion('Environment', 'acme')
        ->expectsChoice('Environment', '0:env-1', ['0:env-1' => 'Acme production  (acme-production)'])
        ->expectsChoice('Which log file?', '/var/www/acme-production/logs/nginx.log', ['/var/www/acme-production/logs/nginx.log' => 'nginx.log'])
        ->assertSuccessful();

    expect($ssh->commands[0])->toContain('rocketeer@acme-production', '/var/www/acme-production/');
});
