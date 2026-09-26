<?php

use App\Actions\PutEnvLocally;
use App\Actions\ReadRemoteEnvFile;
use Saloon\Http\Faking\MockResponse;

beforeEach(fn () => actingInTeam());

function envPullApi(array $servers): void
{
    fakeApi([
        'api.team.environments.index' => MockResponse::make(['data' => [environmentRecord(['slug' => 'habits-production', 'directory_path' => '/home/habits-production/webroot'])], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
        'api.team.environments.servers.index' => MockResponse::make(['data' => $servers, 'meta' => []]),
    ]);
}

it('reads the env file as the environment user from the first connected server', function (): void {
    envPullApi([
        serverRecord(['name' => 'web-1', 'ip' => '10.0.0.1', 'is_connected' => false]),
        serverRecord(['name' => 'web-2', 'ip' => '10.0.0.2', 'is_connected' => true]),
        serverRecord(['name' => 'web-3', 'ip' => '10.0.0.3', 'is_connected' => true]),
    ]);

    $this->mock(ReadRemoteEnvFile::class)->shouldReceive('handle')->once()
        ->with('10.0.0.2', 'habits-production', '/home/habits-production/webroot')
        ->andReturn(['contents' => "APP_ENV=production\nDB_DATABASE=habits\n", 'wordpress' => false]);
    $this->mock(PutEnvLocally::class)->shouldReceive('handle')->once()
        ->withArgs(fn (string $env, string $name, string $directory) => $directory === getcwd()
            && $name === basename(getcwd())
            && str_contains($env, 'APP_ENV=local')
            && str_contains($env, 'DB_DATABASE='.basename(getcwd())))
        ->andReturn(getcwd().'/.env');

    [$code, $output] = runCommand('env:pull', ['environment' => 'habits-production', '--json' => true]);

    expect($code)->toBe(0)->and(json_decode($output, true))->toBe([
        'team' => 'acme',
        'environment' => 'habits-production',
        'server' => '10.0.0.2',
        'wordpress' => false,
        'path' => getcwd().'/.env',
    ]);
});

it('says so when no server of the environment is connected', function (): void {
    envPullApi([serverRecord(['ip' => '10.0.0.1', 'is_connected' => false])]);

    $this->mock(ReadRemoteEnvFile::class)->shouldNotReceive('handle');

    [$code, $output] = runCommand('env:pull', ['environment' => 'habits-production', '--json' => true]);

    expect($code)->toBe(1)->and(json_decode($output, true)['error']['message'])->toBe('Acme production has no connected server.');
});

it('no longer takes a --server option', function (): void {
    expect(Illuminate\Support\Facades\Artisan::all()['env:pull']->getDefinition()->hasOption('server'))->toBeFalse();
});

it('writes the env file into the directory it runs in', function (): void {
    $directory = sys_get_temp_dir().'/rocket-env-pull-'.uniqid();
    mkdir($directory);

    $path = (new PutEnvLocally)->handle("APP_ENV=local\n", 'shop', $directory);

    expect($path)->toBe("{$directory}/.env")->and(file_get_contents($path))->toBe("APP_ENV=local\n");

    unlink($path);
    rmdir($directory);
});
