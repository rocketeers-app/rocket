<?php

use App\Actions\ConfigureDotEnvLocally;
use App\Actions\GetRemoteDotEnv;
use App\Actions\GetRepositoryName;
use App\Actions\IsWordPress;
use App\Actions\PutEnvLocally;
use App\Exceptions\StepException;
use Saloon\Http\Faking\MockResponse;

beforeEach(fn () => actingInTeam());

function envPullApi(array $servers): void
{
    fakeApi([
        'api.team.environments.index' => MockResponse::make(['data' => [environmentRecord(['slug' => 'habits-production'])], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
        'api.team.environments.servers.index' => MockResponse::make(['data' => $servers, 'meta' => []]),
    ]);
}

it('pulls the env file from the environment\'s web server without being told which one', function (): void {
    envPullApi([
        serverRecord(['name' => 'db-1', 'ip' => '10.0.0.9', 'is_web' => false]),
        serverRecord(['name' => 'web-1', 'ip' => '10.0.0.1', 'is_web' => true]),
    ]);

    $this->mock(IsWordPress::class)->shouldReceive('handle')->with('habits-production', '10.0.0.1')->andReturn(false);
    $this->mock(GetRemoteDotEnv::class)->shouldReceive('handle')->with('habits-production', '10.0.0.1')->andReturn("APP_ENV=production\n");
    $this->mock(GetRepositoryName::class)->shouldReceive('handle')->andReturn('habits');
    $this->mock(ConfigureDotEnvLocally::class)->shouldReceive('handle')->with("APP_ENV=production\n", 'habits')->andReturn("APP_ENV=local\n");
    $this->mock(PutEnvLocally::class)->shouldReceive('handle')->once()->with("APP_ENV=local\n", 'habits');

    [$code, $output] = runCommand('env:pull', ['environment' => 'habits-production', '--json' => true]);

    expect($code)->toBe(0)->and(json_decode($output, true))->toBe([
        'team' => 'acme',
        'environment' => 'habits-production',
        'server' => '10.0.0.1',
        'wordpress' => false,
    ]);
});

it('tries the next server when the first has no env file', function (): void {
    envPullApi([
        serverRecord(['name' => 'web-1', 'ip' => '10.0.0.1', 'is_web' => true]),
        serverRecord(['name' => 'web-2', 'ip' => '10.0.0.2', 'is_web' => true]),
    ]);

    $this->mock(IsWordPress::class)->shouldReceive('handle')->andReturn(false);
    $this->mock(GetRemoteDotEnv::class)->shouldReceive('handle')->andReturnUsing(fn (string $site, string $host) => $host === '10.0.0.1'
        ? throw new StepException('missing')
        : "APP_ENV=production\n");
    $this->mock(GetRepositoryName::class)->shouldReceive('handle')->andReturn('habits');
    $this->mock(PutEnvLocally::class)->shouldReceive('handle')->once();

    [$code, $output] = runCommand('env:pull', ['environment' => 'habits-production', '--json' => true]);

    expect($code)->toBe(0)->and(json_decode($output, true)['server'])->toBe('10.0.0.2');
});

it('no longer takes a --server option', function (): void {
    expect(Illuminate\Support\Facades\Artisan::all()['env:pull']->getDefinition()->hasOption('server'))->toBeFalse();
});
