<?php

use App\Actions\OpenInEditor;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    actingInTeam(['environments:read', 'secrets:reveal', 'secrets:update', 'deployments:read', 'deployments:create']);
    config(['rocketeers.deployment_poll_interval' => 0]);

    $this->requests = [];
    $this->saves = [];
    $this->env = "APP_KEY=base64:abc\nDB_PASSWORD=hunter2\nVITE_APP_NAME=Acme";
});

function envEditEditor(object $test, Closure ...$edits): void
{
    $test->opened = [];

    app()->instance(OpenInEditor::class, new class($test, $edits) extends OpenInEditor
    {
        public function __construct(private object $test, private array $edits) {}

        public function handle(string $path): void
        {
            $this->test->opened[] = ['path' => $path, 'contents' => file_get_contents($path), 'mode' => fileperms($path) & 0777, 'directory' => fileperms(dirname($path)) & 0777];

            file_put_contents($path, array_shift($this->edits)(file_get_contents($path)));
        }
    });
}

/** @param array<int, MockResponse> $saves */
function envEditApi(object $test, array $saves = [], array $environment = [], ?Closure $reads = null): void
{
    $records = fn (array $records): MockResponse => MockResponse::make(['data' => $records, 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($records)]]);
    $read = 0;

    fakeApi([
        'api.team.environments.index' => $records([environmentRecord(['id' => 'env-1', 'slug' => 'acme-production', 'supports_env_file' => true, ...$environment])]),
        'api.team.environments.env.show' => function () use ($test, $reads, &$read): MockResponse {
            $test->requests[] = 'read';
            $contents = $reads === null ? $test->env : $reads($read++);

            return MockResponse::make(['data' => ['contents' => $contents, 'checksum' => hash('sha256', $contents), 'filename' => '.env', 'format' => 'dotenv']]);
        },
        'api.team.environments.env.update' => function (PendingRequest $pending) use ($test, $saves): MockResponse {
            $test->requests[] = 'save';
            $test->saves[] = $pending->body()->all();

            return $saves[count($test->saves) - 1] ?? envEditSaved();
        },
        'api.team.environments.deploy' => function () use ($test): MockResponse {
            $test->requests[] = 'deploy';

            return MockResponse::make(['data' => ['id' => 'dep-1', 'started_at' => null]], 201);
        },
        'api.team.environments.deployments.steps' => function () use ($test): MockResponse {
            $test->requests[] = 'steps';

            return MockResponse::make([
                'data' => [['id' => 1, 'title' => 'Building assets', 'status' => 'success', 'duration' => 3.0, 'server' => ['id' => 'srv-1', 'name' => 'web-1']]],
                'deployment' => ['id' => 'dep-1', 'started_at' => now()->toIso8601String(), 'completed_at' => now()->toIso8601String(), 'human_duration' => '40s'],
                'servers' => [['id' => 'srv-1', 'name' => 'web-1']],
            ]);
        },
    ]);
}

function envEditSaved(array $buildTimeKeys = [], array $servers = [['name' => 'web-1', 'ok' => true, 'error' => null], ['name' => 'web-2', 'ok' => true, 'error' => null]]): MockResponse
{
    return MockResponse::make(['data' => ['checksum' => 'new', 'servers' => $servers, 'build_time_keys' => $buildTimeKeys]]);
}

it('saves the edited env with the checksum it was read at, naming keys but never values', function (): void {
    envEditApi($this);
    envEditEditor($this, fn (string $env): string => str_replace('hunter2', 'correct-horse', $env)."\nMAIL_FROM=hi@acme.test");

    $this->artisan('env:edit', ['environment' => 'acme-production'])
        ->expectsConfirmation('Save the env of acme-production and write it to its servers?', 'yes')
        ->expectsConfirmation('Deploy acme-production now?', 'no')
        ->expectsOutputToContain('+ MAIL_FROM')
        ->expectsOutputToContain('~ DB_PASSWORD')
        ->doesntExpectOutputToContain('hunter2')
        ->doesntExpectOutputToContain('correct-horse')
        ->expectsOutputToContain('The env of acme-production is live.')
        ->assertExitCode(0);

    expect($this->requests)->toBe(['read', 'save'])
        ->and($this->saves[0])->toBe(['contents' => str_replace('hunter2', 'correct-horse', $this->env)."\nMAIL_FROM=hi@acme.test", 'if_match' => hash('sha256', $this->env)])
        ->and($this->opened[0]['contents'])->toBe($this->env)
        ->and(basename($this->opened[0]['path']))->toBe('acme-production.env')
        ->and($this->opened[0]['mode'])->toBe(0600)
        ->and($this->opened[0]['directory'])->toBe(0700)
        ->and(file_exists($this->opened[0]['path']))->toBeFalse()
        ->and(is_dir(dirname($this->opened[0]['path'])))->toBeFalse();
});

it('saves nothing when the file is unchanged', function (): void {
    envEditApi($this);
    envEditEditor($this, fn (string $env): string => $env);

    $this->artisan('env:edit', ['environment' => 'acme-production'])
        ->expectsOutputToContain('No changes.')
        ->assertExitCode(0);

    expect($this->requests)->toBe(['read'])
        ->and(file_exists($this->opened[0]['path']))->toBeFalse();
});

it('offers to deploy when a build-time key changed and follows the deployment', function (): void {
    envEditApi($this, [envEditSaved(['VITE_APP_NAME'])]);
    envEditEditor($this, fn (string $env): string => str_replace('VITE_APP_NAME=Acme', 'VITE_APP_NAME=Rocket', $env));

    $this->artisan('env:edit', ['environment' => 'acme-production'])
        ->expectsConfirmation('Save the env of acme-production and write it to its servers?', 'yes')
        ->expectsOutputToContain('VITE_APP_NAME is read at build time, so it takes effect after a deploy.')
        ->expectsConfirmation('Deploy acme-production now?', 'yes')
        ->expectsOutputToContain('Deployed acme-production in 40s')
        ->assertExitCode(0);

    expect($this->requests)->toBe(['read', 'save', 'deploy', 'steps']);
});

it('reopens the editor with your changes when the API refuses them', function (): void {
    envEditApi($this, [
        MockResponse::make(['message' => 'The contents field is invalid.', 'errors' => ['contents' => ['APP_KEY is required for a Laravel application.']]], 422),
    ]);
    envEditEditor(
        $this,
        fn (string $env): string => str_replace('APP_KEY=base64:abc', 'APP_KEY=', $env),
        fn (string $env): string => str_replace('APP_KEY=', 'APP_KEY=base64:new', $env),
    );

    $this->artisan('env:edit', ['environment' => 'acme-production', '--no-deploy' => true])
        ->expectsConfirmation('Save the env of acme-production and write it to its servers?', 'yes')
        ->expectsOutputToContain('APP_KEY is required for a Laravel application.')
        ->expectsConfirmation('Open the editor again with your changes?', 'yes')
        ->expectsConfirmation('Save the env of acme-production and write it to its servers?', 'yes')
        ->assertExitCode(0);

    expect($this->opened[1]['contents'])->toBe(str_replace('APP_KEY=base64:abc', 'APP_KEY=', $this->env))
        ->and($this->saves[1]['contents'])->toContain('APP_KEY=base64:new')
        ->and($this->saves[1]['if_match'])->toBe(hash('sha256', $this->env));
});

it('reopens the editor with the current env when someone changed it in the meantime', function (): void {
    $current = $this->env."\nADDED_ELSEWHERE=1";

    envEditApi($this, [
        MockResponse::make(['message' => 'The env changed since you opened it. Reload it and apply your change again.'], 409),
    ], reads: fn (int $read): string => $read === 0 ? $this->env : $current);
    envEditEditor(
        $this,
        fn (string $env): string => $env."\nMINE=1",
        fn (string $env): string => $env."\nMINE=1",
    );

    $this->artisan('env:edit', ['environment' => 'acme-production', '--no-deploy' => true])
        ->expectsConfirmation('Save the env of acme-production and write it to its servers?', 'yes')
        ->expectsOutputToContain('Someone changed the env since you opened it')
        ->expectsOutputToContain('You had changed: MINE')
        ->expectsConfirmation('Open the editor again with the current env?', 'yes')
        ->expectsConfirmation('Save the env of acme-production and write it to its servers?', 'yes')
        ->assertExitCode(0);

    expect($this->requests)->toBe(['read', 'save', 'read', 'save'])
        ->and($this->opened[1]['contents'])->toBe($current)
        ->and($this->saves[1]['if_match'])->toBe(hash('sha256', $current));
});

it('deploys without asking with --deploy', function (): void {
    envEditApi($this);
    envEditEditor($this, fn (string $env): string => $env."\nNEW=1");

    $this->artisan('env:edit', ['environment' => 'acme-production', '--deploy' => true])
        ->expectsConfirmation('Save the env of acme-production and write it to its servers?', 'yes')
        ->assertExitCode(0);

    expect($this->requests)->toBe(['read', 'save', 'deploy', 'steps']);
});

it('does not deploy with --no-deploy, even when a build-time key changed', function (): void {
    envEditApi($this, [envEditSaved(['VITE_APP_NAME'])]);
    envEditEditor($this, fn (string $env): string => str_replace('Acme', 'Rocket', $env));

    $this->artisan('env:edit', ['environment' => 'acme-production', '--no-deploy' => true])
        ->expectsConfirmation('Save the env of acme-production and write it to its servers?', 'yes')
        ->expectsOutputToContain('Deploy later with `rocket deploy acme-production`')
        ->assertExitCode(0);

    expect($this->requests)->toBe(['read', 'save']);
});

it('names a server that did not get the file and does not offer to deploy', function (): void {
    envEditApi($this, [envEditSaved(servers: [['name' => 'web-1', 'ok' => true, 'error' => null], ['name' => 'web-2', 'ok' => false, 'error' => 'Connection timed out']])]);
    envEditEditor($this, fn (string $env): string => $env."\nNEW=1");

    $this->artisan('env:edit', ['environment' => 'acme-production'])
        ->expectsConfirmation('Save the env of acme-production and write it to its servers?', 'yes')
        ->expectsOutputToContain('Connection timed out')
        ->expectsOutputToContain('web-2 did not get it')
        ->assertExitCode(1);

    expect($this->requests)->toBe(['read', 'save']);
});

it('refuses to edit without a terminal', function (): void {
    envEditApi($this);

    [$code, $output] = runCommand('env:edit', ['environment' => 'acme-production', '--json' => true]);

    expect($code)->toBe(1)
        ->and(json_decode($output, true)['error']['message'])->toBe('env:edit opens your editor; run it in a terminal.')
        ->and($this->requests)->toBe([]);
});

it('has no env file to edit for WordPress', function (): void {
    envEditApi($this, environment: ['supports_env_file' => false]);

    $this->artisan('env:edit', ['environment' => 'acme-production'])
        ->expectsOutputToContain('WordPress keeps its configuration in wp-config.php')
        ->assertExitCode(1);

    expect($this->requests)->toBe([]);
});

it('says which permission is missing before reading anything', function (): void {
    actingInTeam(['environments:read', 'secrets:read', 'secrets:update']);
    envEditApi($this);

    $this->artisan('env:edit', ['environment' => 'acme-production'])
        ->expectsOutputToContain('Reveal Secrets')
        ->assertExitCode(1);

    expect($this->requests)->toBe([]);
});
