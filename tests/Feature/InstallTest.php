<?php

use App\Actions\ComposerInstall;
use App\Actions\GetRemoteRepositoryUrl;
use App\Actions\ImportServerDatabase;
use App\Actions\IsolatePhpVersion;
use App\Actions\NpmBuild;
use App\Actions\NpmInstall;
use App\Actions\ParkDirectory;
use App\Actions\PrepareLocalRepository;
use App\Actions\ReadRemoteEnvFile;
use App\Actions\RunMigrations;
use App\Actions\SecureSite;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    actingInTeam();

    $this->projects = sys_get_temp_dir().'/rocket-install-'.uniqid();
    $this->directory = $this->projects.'/routine';
    (new Filesystem)->ensureDirectoryExists($this->directory);

    foreach (['composer.json', 'artisan', 'package.json'] as $file) {
        touch("{$this->directory}/{$file}");
    }

    config(['rocketeers.projects_path' => $this->projects]);

    $this->calls = [];
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->projects);
});

function installDatabase(string $name, string $driver, string $ip = '10.0.0.9'): array
{
    return ['id' => 'db-'.$name, 'name' => $name, 'driver' => $driver, 'server' => ['name' => 'db-1', 'ip' => $ip]];
}

function installApi(array $databases, array $details = []): void
{
    $records = fn (array $records): MockResponse => MockResponse::make(['data' => $records, 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($records)]]);

    fakeApi([
        'api.team.environments.index' => $records([environmentRecord(['slug' => 'routine-production', 'name' => 'Routine production'])]),
        'api.team.environments.show' => MockResponse::make(['data' => environmentRecord([
            'slug' => 'routine-production',
            'name' => 'Routine production',
            'label' => 'production',
            'branch' => 'main',
            'php_version' => '8.3',
            'is_php_based' => true,
            'supports_env_file' => true,
            'directory_path' => '/home/routine-production/webroot',
            'repository' => ['id' => 'repo-1', 'ssh_url' => 'git@github.com:acme/routine.git'],
            ...$details,
        ])]),
        'api.team.environments.servers.index' => $records([serverRecord(['ip' => '10.0.0.2', 'is_connected' => true])]),
        'api.team.environments.databases.index' => $records($databases),
    ]);
}

function installMock(string $class): MockInterface
{
    $mock = Mockery::mock($class);
    app()->instance($class, $mock);

    return $mock;
}

function recordInstallSteps(object $test, bool $dirty = false, string $currentBranch = 'main'): void
{
    $record = function (string $step) use ($test): Closure {
        return function (...$arguments) use ($test, $step): void {
            $test->calls[] = [$step, $arguments];
        };
    };

    $repository = installMock(PrepareLocalRepository::class);
    $repository->shouldReceive('isDirty')->andReturn($dirty);
    $repository->shouldReceive('currentBranch')->andReturn($currentBranch);
    $repository->shouldReceive('handle')->andReturnUsing(function (...$arguments) use ($test): string {
        $test->calls[] = ['repository', $arguments];

        return 'updated';
    });

    installMock(ReadRemoteEnvFile::class)->shouldReceive('handle')
        ->with('10.0.0.2', 'routine-production', '/home/routine-production/webroot')
        ->andReturn(['contents' => "APP_ENV=production\nAPP_URL=https://routine.app\nDB_CONNECTION=mysql\nDB_DATABASE=routine_prod\nDB_USERNAME=routine\nDB_PASSWORD=secret\n", 'wordpress' => false]);

    foreach ([
        'database' => ImportServerDatabase::class,
        'park' => ParkDirectory::class,
        'isolate' => IsolatePhpVersion::class,
        'composer' => ComposerInstall::class,
        'migrate' => RunMigrations::class,
        'npm' => NpmInstall::class,
        'build' => NpmBuild::class,
        'secure' => SecureSite::class,
    ] as $step => $action) {
        installMock($action)->shouldReceive('handle')->andReturnUsing($record($step));
    }
}

function installSteps(array $calls): array
{
    return array_map(fn (array $call): string => $call[0], $calls);
}

it('installs an environment into a directory named after its slug without the label', function (): void {
    installApi([installDatabase('routine_prod', 'mysql_native')]);
    recordInstallSteps($this);

    [$code, $output] = runCommand('install', ['environment' => 'routine-production', '--json' => true]);

    expect($code)->toBe(0)
        ->and(json_decode($output, true))->toBe([
            'team' => 'acme',
            'environment' => 'routine-production',
            'path' => $this->directory,
            'repository_path' => $this->directory,
            'url' => 'https://routine.test',
            'databases' => [['name' => 'routine_prod', 'engine' => 'mysql', 'server' => '10.0.0.9', 'local' => 'routine', 'main' => true]],
        ])
        ->and(installSteps($this->calls))->toBe(['repository', 'database', 'isolate', 'composer', 'migrate', 'npm', 'build', 'secure'])
        ->and($this->calls[0][1])->toBe([$this->directory, 'git@github.com:acme/routine.git', 'main', false])
        ->and($this->calls[1][1])->toBe([installDatabase('routine_prod', 'mysql_native'), 'routine-production', ['10.0.0.2'], 'routine', '/home/routine-production/webroot'])
        ->and($this->calls[2][1])->toBe(['routine', '8.3', $this->directory]);

    expect(file_get_contents($this->directory.'/.env'))
        ->toContain('APP_ENV=local', 'APP_URL=https://routine.test', 'DB_CONNECTION=mysql', 'DB_DATABASE=routine', 'DB_USERNAME=root', 'DB_PASSWORD=')
        ->not->toContain('secret');
});

it('skips composer, migrations and npm when the project has none of them', function (): void {
    foreach (['composer.json', 'artisan', 'package.json'] as $file) {
        unlink("{$this->directory}/{$file}");
    }

    installApi([], ['is_php_based' => false]);
    recordInstallSteps($this);

    [$code, $output] = runCommand('install', ['environment' => 'routine-production']);

    expect($code)->toBe(0)
        ->and(installSteps($this->calls))->toBe(['repository', 'secure'])
        ->and($output)->toContain('No MySQL or PostgreSQL database', 'View in browser: https://routine.test');
});

it('reads the repository from the current release when the API has none', function (): void {
    installApi([], ['repository' => null]);
    recordInstallSteps($this);

    $this->mock(GetRemoteRepositoryUrl::class)->shouldReceive('handle')->once()
        ->with('10.0.0.2', 'routine-production', '/home/routine-production/webroot')
        ->andReturn('git@github.com:acme/routine-legacy.git');

    [$code] = runCommand('install', ['environment' => 'routine-production', '--json' => true]);

    expect($code)->toBe(0)->and($this->calls[0][1][1])->toBe('git@github.com:acme/routine-legacy.git');
});

it('stashes local changes when you say so', function (): void {
    installApi([]);
    recordInstallSteps($this, dirty: true);

    $this->artisan('install', ['environment' => 'routine-production'])
        ->expectsConfirmation("{$this->directory} has local changes. Stash your local changes?", 'yes')
        ->assertSuccessful();

    expect($this->calls[0])->toBe(['repository', [$this->directory, 'git@github.com:acme/routine.git', 'main', true]]);
});

it('stops without touching anything when you keep your local changes', function (): void {
    installApi([]);
    recordInstallSteps($this, dirty: true);

    $this->artisan('install', ['environment' => 'routine-production'])
        ->expectsConfirmation("{$this->directory} has local changes. Stash your local changes?", 'no')
        ->expectsOutputToContain('Stopped')
        ->assertFailed();

    expect($this->calls)->toBe([]);
});

it('refuses to decide about local changes when it cannot ask', function (): void {
    installApi([]);
    recordInstallSteps($this, dirty: true);

    [$code, $output] = runCommand('install', ['environment' => 'routine-production', '--json' => true]);

    expect($code)->toBe(1)
        ->and(json_decode($output, true)['error']['message'])->toContain('has local changes')
        ->and($this->calls)->toBe([]);
});

it('imports every database when asked, the main one under the local name and DB_CONNECTION pointing at it', function (): void {
    installApi([installDatabase('routine_prod', 'mysql_native'), installDatabase('ledger', 'pgsql_native', '10.0.0.10')]);
    recordInstallSteps($this);

    $this->artisan('install', ['environment' => 'routine-production'])
        ->expectsChoice('Which databases do you want to import?', 'all', ['one' => 'Only one', 'all' => 'All 2'])
        ->expectsChoice('Which one is the main connection (DB_CONNECTION)?', '#1', [
            '#0' => 'routine_prod · MySQL · on db-1 (10.0.0.9)',
            '#1' => 'ledger · PostgreSQL · on db-1 (10.0.0.10)',
        ])
        ->expectsOutputToContain('ledger → local routine')
        ->assertSuccessful();

    $imports = array_values(array_filter($this->calls, fn (array $call): bool => $call[0] === 'database'));

    expect(array_map(fn (array $call): array => [$call[1][0]['name'], $call[1][3]], $imports))->toBe([['routine_prod', 'routine_prod'], ['ledger', 'routine']])
        ->and(file_get_contents($this->directory.'/.env'))->toContain('DB_CONNECTION=pgsql', 'DB_DATABASE=routine', 'DB_USERNAME=root');
});

it('imports only the database you pick', function (): void {
    installApi([installDatabase('routine_prod', 'mysql_native'), installDatabase('ledger', 'pgsql_native', '10.0.0.10')]);
    recordInstallSteps($this);

    $this->artisan('install', ['environment' => 'routine-production'])
        ->expectsChoice('Which databases do you want to import?', 'one', ['one' => 'Only one', 'all' => 'All 2'])
        ->expectsChoice('Which database do you want to import?', '#0', [
            '#0' => 'routine_prod · MySQL · on db-1 (10.0.0.9)',
            '#1' => 'ledger · PostgreSQL · on db-1 (10.0.0.10)',
        ])
        ->assertSuccessful();

    $imports = array_values(array_filter($this->calls, fn (array $call): bool => $call[0] === 'database'));

    expect($imports)->toHaveCount(1)->and($imports[0][1][3])->toBe('routine');
});

it('takes the database named in the remote DB_DATABASE when it cannot ask', function (): void {
    installApi([installDatabase('ledger', 'pgsql_native', '10.0.0.10'), installDatabase('routine_prod', 'mysql_native')]);
    recordInstallSteps($this);

    [$code, $output] = runCommand('install', ['environment' => 'routine-production', '--json' => true]);

    expect($code)->toBe(0)->and(json_decode($output, true)['databases'])->toBe([
        ['name' => 'routine_prod', 'engine' => 'mysql', 'server' => '10.0.0.9', 'local' => 'routine', 'main' => true],
    ]);
});

it('imports all with --all, the main connection named by --database', function (): void {
    installApi([installDatabase('routine_prod', 'mysql_native'), installDatabase('ledger', 'pgsql_native', '10.0.0.10')]);
    recordInstallSteps($this);

    [$code, $output] = runCommand('install', ['environment' => 'routine-production', '--all' => true, '--database' => 'ledger', '--json' => true]);

    expect($code)->toBe(0)->and(array_column(json_decode($output, true)['databases'], 'local', 'name'))->toBe(['routine_prod' => 'routine_prod', 'ledger' => 'routine']);
});

it('asks for --database or --all when several databases match nothing and it cannot ask', function (): void {
    installApi([installDatabase('ledger', 'pgsql_native', '10.0.0.10'), installDatabase('events', 'mysql_native')]);
    recordInstallSteps($this);

    [$code, $output] = runCommand('install', ['environment' => 'routine-production', '--json' => true]);

    expect($code)->toBe(1)
        ->and(json_decode($output, true)['error']['message'])->toContain('several databases: ledger, events', '--database=<name> or --all')
        ->and($this->calls)->toBe([]);
});

function installMonorepo(object $test): string
{
    $directory = $test->projects.'/monorepo/apps/api';
    (new Filesystem)->ensureDirectoryExists($directory);

    foreach (['composer.json', 'artisan', 'package.json'] as $file) {
        touch("{$directory}/{$file}");
    }

    installApi([installDatabase('routine_prod', 'mysql_native')], [
        'root_directory' => '/apps/api/',
        'repository' => ['id' => 'repo-1', 'ssh_url' => 'git@github.com:acme/monorepo.git'],
    ]);

    return $directory;
}

it('installs an environment in a monorepo in its root directory, named after it and parked in Herd', function (): void {
    $directory = installMonorepo($this);
    recordInstallSteps($this);

    [$code, $output] = runCommand('install', ['environment' => 'routine-production', '--json' => true]);

    expect($code)->toBe(0)
        ->and(json_decode($output, true))->toMatchArray([
            'path' => $directory,
            'repository_path' => $this->projects.'/monorepo',
            'url' => 'https://api.test',
            'databases' => [['name' => 'routine_prod', 'engine' => 'mysql', 'server' => '10.0.0.9', 'local' => 'api', 'main' => true]],
        ])
        ->and(installSteps($this->calls))->toBe(['repository', 'database', 'park', 'isolate', 'composer', 'migrate', 'npm', 'build', 'secure'])
        ->and($this->calls[0][1])->toBe([$this->projects.'/monorepo', 'git@github.com:acme/monorepo.git', 'main', false])
        ->and($this->calls[2][1])->toBe([$this->projects.'/monorepo/apps'])
        ->and($this->calls[3][1])->toBe(['api', '8.3', $directory])
        ->and($this->calls[6][1])->toBe(['api', $directory])
        ->and($this->calls[8][1])->toBe(['api', $directory]);

    expect(file_get_contents("{$directory}/.env"))->toContain('APP_URL=https://api.test', 'DB_DATABASE=api')
        ->and(file_exists($this->projects.'/monorepo/.env'))->toBeFalse();
});

it('runs npm install at the repository root when it has workspaces', function (): void {
    $directory = installMonorepo($this);
    file_put_contents($this->projects.'/monorepo/package.json', json_encode(['workspaces' => ['apps/*']]));
    recordInstallSteps($this);

    [$code] = runCommand('install', ['environment' => 'routine-production', '--json' => true]);

    $calls = array_column($this->calls, 1, 0);

    expect($code)->toBe(0)
        ->and($calls['npm'])->toBe(['api', $this->projects.'/monorepo'])
        ->and($calls['build'])->toBe([$directory]);
});

it('switches the branch of a monorepo when you say so', function (): void {
    installMonorepo($this);
    recordInstallSteps($this, currentBranch: 'develop');

    $this->artisan('install', ['environment' => 'routine-production'])
        ->expectsConfirmation("{$this->projects}/monorepo is on develop. Switch it to main? This affects every app in the repository.", 'yes')
        ->assertSuccessful();

    expect($this->calls[0])->toBe(['repository', [$this->projects.'/monorepo', 'git@github.com:acme/monorepo.git', 'main', false]]);
});

it('stops without touching anything when you keep the branch of a monorepo', function (): void {
    installMonorepo($this);
    recordInstallSteps($this, currentBranch: 'develop');

    $this->artisan('install', ['environment' => 'routine-production'])
        ->expectsConfirmation("{$this->projects}/monorepo is on develop. Switch it to main? This affects every app in the repository.", 'no')
        ->expectsOutputToContain('Stopped')
        ->assertFailed();

    expect($this->calls)->toBe([]);
});

it('refuses to switch the branch of a monorepo when it cannot ask', function (): void {
    installMonorepo($this);
    recordInstallSteps($this, currentBranch: 'develop');

    [$code, $output] = runCommand('install', ['environment' => 'routine-production', '--json' => true]);

    expect($code)->toBe(1)
        ->and(json_decode($output, true)['error']['message'])->toContain('is on develop, not main')
        ->and($this->calls)->toBe([]);
});

it('shows each step on its own line instead of a progress bar with --verbose', function (): void {
    installApi([installDatabase('routine_prod', 'mysql_native')]);
    recordInstallSteps($this);

    [$code, $output] = runCommand('install', ['environment' => 'routine-production', '--verbose' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('→ Preparing the repository on main', '→ Importing routine_prod from 10.0.0.9', '→ Running npm install', '→ Securing the site')
        ->not->toContain('[▓');
});

it('no longer takes --server or --php', function (): void {
    $definition = Artisan::all()['install']->getDefinition();

    expect($definition->hasOption('server'))->toBeFalse()
        ->and($definition->hasOption('php'))->toBeFalse()
        ->and($definition->hasArgument('environment'))->toBeTrue();
});
