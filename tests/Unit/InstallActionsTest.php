<?php

use App\Actions\ConfigureDotEnvLocally;
use App\Actions\ImportServerDatabase;
use App\Actions\LocalProjectName;
use App\Actions\LocalRepositoryName;
use App\Actions\NpmBuild;
use App\Actions\NpmInstall;
use App\Actions\PrepareLocalRepository;
use App\Actions\SetEnvValues;
use App\Exceptions\StepException;
use App\Support\CommandLog;
use App\Support\ProcessError;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

it('names the local project after the slug without its label', function (array $environment, string $name): void {
    expect((new LocalProjectName)->handle($environment))->toBe($name);
})->with([
    'label at the end' => [['slug' => 'routine-production', 'label' => 'production'], 'routine'],
    'label with a dash' => [['slug' => 'shop-pre-production', 'label' => 'pre-production'], 'shop'],
    'label not in the slug' => [['slug' => 'routine-live', 'label' => 'production'], 'routine-live'],
    'no label' => [['slug' => 'routine', 'label' => null], 'routine'],
    'slug is the label' => [['slug' => 'production', 'label' => 'production'], 'production'],
    'root directory' => [['slug' => 'routine-production', 'label' => 'production', 'root_directory' => 'apps/api'], 'api'],
    'root directory with slashes' => [['slug' => 'routine-production', 'label' => 'production', 'root_directory' => '/apps/api/'], 'api'],
    'root directory one level deep' => [['slug' => 'routine-production', 'label' => 'production', 'root_directory' => 'api'], 'api'],
    'empty root directory' => [['slug' => 'routine-production', 'label' => 'production', 'root_directory' => ''], 'routine'],
]);

it('names the local repository after its clone URL', function (string $url, string $name): void {
    expect((new LocalRepositoryName)->handle($url))->toBe($name);
})->with([
    'ssh' => ['git@github.com:acme/monorepo.git', 'monorepo'],
    'ssh without .git' => ['git@github.com:acme/monorepo', 'monorepo'],
    'ssh without an owner' => ['git@example.test:monorepo.git', 'monorepo'],
    'https' => ['https://github.com/acme/monorepo.git', 'monorepo'],
    'https without .git' => ['https://github.com/acme/monorepo/', 'monorepo'],
]);

it('replaces keys that are there and appends the ones that are not', function (): void {
    expect((new SetEnvValues)->handle("APP_ENV=local\nDB_CONNECTION=mysql\n", ['DB_CONNECTION' => 'pgsql', 'DB_PASSWORD' => '$ecret']))
        ->toBe("APP_ENV=local\nDB_CONNECTION=pgsql\nDB_PASSWORD=\$ecret\n")
        ->and((new SetEnvValues)->handle('APP_ENV=local', ['DB_CONNECTION' => 'mysql']))->toBe("APP_ENV=local\nDB_CONNECTION=mysql\n")
        ->and((new SetEnvValues)->handle('', ['DB_CONNECTION' => 'mysql']))->toBe("DB_CONNECTION=mysql\n");
});

it('points the env at the local database server of the engine', function (): void {
    $env = "DB_CONNECTION=pgsql\nDB_HOST=10.0.0.9\nDB_PORT=6432\nDB_USERNAME=routine\nDB_PASSWORD=secret\n";

    expect((new ConfigureDotEnvLocally)->handle($env, 'routine'))->toContain('DB_HOST=127.0.0.1', 'DB_PORT=5432', 'DB_USERNAME=root', "DB_PASSWORD=\n")
        ->and((new ConfigureDotEnvLocally)->handle($env, 'routine', 'mysql'))->toContain('DB_PORT=3306', 'DB_USERNAME=root')
        ->and((new ConfigureDotEnvLocally)->handle("DB_CONNECTION=mysql\nDB_SOCKET=/var/run/mysqld/mysqld.sock\n", 'routine'))->toContain("DB_SOCKET=\n")->not->toContain('mysqld.sock');
});

function fakeNvm(): string
{
    $nvm = sys_get_temp_dir().'/rocket-nvm-'.uniqid();
    mkdir($nvm);
    touch("{$nvm}/nvm.sh");
    putenv("NVM_DIR={$nvm}");

    return $nvm;
}

afterEach(fn () => putenv('NVM_DIR'));

it('uses nvm only when the project pins a Node version, and never starts a dev server', function (): void {
    $nvm = fakeNvm();
    $directory = sys_get_temp_dir().'/rocket-npm-'.uniqid();
    mkdir($directory);

    expect((new NpmInstall)->command($directory))->toBe('npm install');

    touch("{$directory}/.nvmrc");

    expect((new NpmInstall)->command($directory))
        ->toBe("export NVM_DIR='{$nvm}' && . '{$nvm}/nvm.sh' && { nvm use || nvm install; } && npm install")
        ->not->toContain('npm run dev');

    (new Filesystem)->deleteDirectory($directory);
    (new Filesystem)->deleteDirectory($nvm);
});

it('finds the nvm of Herd when NVM_DIR is not set', function (): void {
    $home = getenv('HOME');
    $fakeHome = sys_get_temp_dir().'/rocket-home-'.uniqid();
    mkdir("{$fakeHome}/Library/Application Support/Herd/config/nvm", 0755, true);
    touch("{$fakeHome}/Library/Application Support/Herd/config/nvm/nvm.sh");
    putenv("HOME={$fakeHome}");

    expect((new NpmInstall)->nvm())->toBe(["{$fakeHome}/Library/Application Support/Herd/config/nvm", "{$fakeHome}/Library/Application Support/Herd/config/nvm/nvm.sh"]);

    putenv("HOME={$home}");
    (new Filesystem)->deleteDirectory($fakeHome);
});

it('says so when the project pins a Node version but there is no nvm', function (): void {
    $home = getenv('HOME');
    $directory = sys_get_temp_dir().'/rocket-npm-'.uniqid();
    mkdir($directory);
    touch("{$directory}/.nvmrc");
    putenv("HOME={$directory}");

    expect(fn () => (new NpmInstall)->command($directory))->toThrow(StepException::class, 'nvm was not found');

    putenv("HOME={$home}");
    (new Filesystem)->deleteDirectory($directory);
})->skip(is_file('/opt/homebrew/opt/nvm/nvm.sh') || is_file('/usr/local/opt/nvm/nvm.sh'), 'nvm is installed with Homebrew here');

it('uses nvm when a parent directory pins a Node version, like in a monorepo', function (): void {
    fakeNvm();
    $root = sys_get_temp_dir().'/rocket-npm-'.uniqid();
    mkdir("{$root}/apps/api", 0755, true);
    touch("{$root}/.nvmrc");

    expect((new NpmInstall)->command("{$root}/apps/api"))->toEndWith('{ nvm use || nvm install; } && npm install');

    (new Filesystem)->deleteDirectory($root);
});

it('builds with the build script, else prod, else production, else not at all', function (array $scripts, ?string $script): void {
    $directory = sys_get_temp_dir().'/rocket-npm-build-'.uniqid();
    mkdir($directory);
    file_put_contents("{$directory}/package.json", json_encode(['scripts' => $scripts]));

    expect((new NpmBuild)->script($directory))->toBe($script);

    (new Filesystem)->deleteDirectory($directory);
})->with([
    'build' => [['dev' => 'vite', 'build' => 'vite build', 'prod' => 'mix --production'], 'build'],
    'prod' => [['dev' => 'mix', 'prod' => 'mix --production', 'production' => 'mix --production'], 'prod'],
    'production' => [['dev' => 'mix', 'production' => 'mix --production'], 'production'],
    'none' => [['dev' => 'vite', 'test' => 'vitest'], null],
]);

it('skips the build without running npm when there is no build script', function (): void {
    $directory = sys_get_temp_dir().'/rocket-npm-build-'.uniqid();
    mkdir($directory);
    file_put_contents("{$directory}/package.json", json_encode(['scripts' => ['dev' => 'vite']]));

    expect((new NpmBuild)->handle($directory))->toBeNull();

    (new Filesystem)->deleteDirectory($directory);
});

it('runs the build script through nvm when the project pins a Node version', function (): void {
    fakeNvm();
    $directory = sys_get_temp_dir().'/rocket-npm-build-'.uniqid();
    mkdir($directory);
    touch("{$directory}/.nvmrc");

    expect((new NpmInstall)->command($directory, 'npm run build'))->toEndWith('{ nvm use || nvm install; } && npm run build');

    (new Filesystem)->deleteDirectory($directory);
});

it('reads the MySQL user from a remote .env or wp-config', function (): void {
    $action = new ImportServerDatabase;

    expect($action->credentialsFrom(['contents' => "DB_USERNAME=routine\nDB_PASSWORD=\"it's\"\n", 'wordpress' => false]))->toBe(['DB_USERNAME' => 'routine', 'DB_PASSWORD' => "it's"])
        ->and($action->credentialsFrom(['contents' => "DB_USER=blog\n", 'wordpress' => false]))->toBe(['DB_USERNAME' => 'blog', 'DB_PASSWORD' => ''])
        ->and($action->credentialsFrom(['contents' => "define('DB_USER', 'blog');\ndefine( \"DB_PASSWORD\", \"pw\" );", 'wordpress' => true]))->toBe(['DB_USERNAME' => 'blog', 'DB_PASSWORD' => 'pw'])
        ->and($action->credentialsFrom(['contents' => "APP_ENV=production\n", 'wordpress' => false]))->toBeNull();
});

it('clones a repository on its branch, then stashes, checks out and pulls on the next run', function (): void {
    $root = sys_get_temp_dir().'/rocket-repo-'.uniqid();
    $git = fn (string $cwd, string ...$arguments): string => tap(new Process(['git', '-c', 'user.name=Rocket', '-c', 'user.email=rocket@example.test', ...$arguments], $cwd))->mustRun()->getOutput();

    mkdir("{$root}/origin", 0755, true);
    $git("{$root}/origin", 'init', '--initial-branch=main');
    file_put_contents("{$root}/origin/README.md", "one\n");
    $git("{$root}/origin", 'add', '.');
    $git("{$root}/origin", 'commit', '-m', 'one');
    $git("{$root}/origin", 'checkout', '-b', 'develop');

    $action = new PrepareLocalRepository;
    $local = "{$root}/www/routine";

    expect($action->handle($local, "{$root}/origin", 'develop'))->toBe('cloned')
        ->and(trim($git($local, 'branch', '--show-current')))->toBe('develop')
        ->and($action->isDirty($local))->toBeFalse();

    file_put_contents("{$root}/origin/README.md", "two\n");
    $git("{$root}/origin", 'commit', '-am', 'two');
    file_put_contents("{$local}/scratch.txt", "local\n");

    expect($action->isDirty($local))->toBeTrue()
        ->and($action->handle($local, "{$root}/origin", 'develop', stash: true))->toBe('updated')
        ->and(file_get_contents("{$local}/README.md"))->toBe("two\n")
        ->and(file_exists("{$local}/scratch.txt"))->toBeFalse()
        ->and($git($local, 'stash', 'list'))->toContain('rocket install');

    $git($local, 'checkout', '-b', 'scratch');
    $git("{$root}/origin", 'checkout', '-b', 'feature');
    file_put_contents("{$root}/origin/README.md", "three\n");
    $git("{$root}/origin", 'commit', '-am', 'three');
    $git("{$root}/origin", 'checkout', 'develop');

    expect($action->handle($local, "{$root}/origin", 'feature'))->toBe('updated')
        ->and($action->currentBranch($local))->toBe('feature')
        ->and(file_get_contents("{$local}/README.md"))->toBe("three\n")
        ->and($action->handle($local, "{$root}/origin", null))->toBe('updated')
        ->and($action->currentBranch($local))->toBe('develop')
        ->and(fn () => $action->handle($local, "{$root}/origin", 'missing'))->toThrow(StepException::class, 'The branch missing is not on origin');

    (new Filesystem)->deleteDirectory($root);
});

it('shows the error from stdout when a process prints it there, like artisan and nvm', function (): void {
    $process = Process::fromShellCommandline("printf '\\n   Illuminate\\\\Database\\\\QueryException \\n\\n  SQLSTATE[HY000] [2002] No such file or directory\\n\\n  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:760\\n'; exit 1");
    $process->run();

    $silent = Process::fromShellCommandline('exit 3');
    $silent->run();

    expect(ProcessError::message($process))->toBe('Illuminate\\Database\\QueryException SQLSTATE[HY000] [2002] No such file or directory')
        ->and(ProcessError::message($silent))->toBe('exit code 3 without output');
});

it('logs commands and their output only when verbose, masking secrets and hidden output', function (): void {
    $log = new CommandLog;
    $quiet = $log->run(Process::fromShellCommandline('echo hello'));

    $output = new BufferedOutput;
    $log->enable($output);
    $log->hide('s3cr3t');
    $log->step('Importing routine');
    $log->run(Process::fromShellCommandline("echo one; echo two >&2; MYSQL_PWD='s3cr3t' true"));
    $log->run(Process::fromShellCommandline('echo APP_KEY=base64:secret'), showOutput: false);
    $log->run(Process::fromShellCommandline('exit 4'));

    expect($quiet->getOutput())->toBe("hello\n")
        ->and($output->fetch())->toContain('→ Importing routine', '$ echo one; echo two >&2; MYSQL_PWD=\'***\' true', '│ one', '│ two', '(output hidden)', 'exit code 4')
        ->not->toContain('s3cr3t', 'APP_KEY');
});
