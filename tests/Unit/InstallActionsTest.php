<?php

use App\Actions\ConfigureDotEnvLocally;
use App\Actions\ImportServerDatabase;
use App\Actions\LocalProjectName;
use App\Actions\NpmBuild;
use App\Actions\NpmInstall;
use App\Actions\PrepareLocalRepository;
use App\Actions\SetEnvValues;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('names the local project after the slug without its label', function (array $environment, string $name): void {
    expect((new LocalProjectName)->handle($environment))->toBe($name);
})->with([
    'label at the end' => [['slug' => 'routine-production', 'label' => 'production'], 'routine'],
    'label with a dash' => [['slug' => 'shop-pre-production', 'label' => 'pre-production'], 'shop'],
    'label not in the slug' => [['slug' => 'routine-live', 'label' => 'production'], 'routine-live'],
    'no label' => [['slug' => 'routine', 'label' => null], 'routine'],
    'slug is the label' => [['slug' => 'production', 'label' => 'production'], 'production'],
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
        ->and((new ConfigureDotEnvLocally)->handle($env, 'routine', 'mysql'))->toContain('DB_PORT=3306', 'DB_USERNAME=root');
});

it('uses nvm only when the project pins a Node version, and never starts a dev server', function (): void {
    $directory = sys_get_temp_dir().'/rocket-npm-'.uniqid();
    mkdir($directory);

    expect((new NpmInstall)->command($directory))->toBe('npm install');

    touch("{$directory}/.nvmrc");

    expect((new NpmInstall)->command($directory))->toContain('nvm use && npm install')->not->toContain('npm run dev');

    (new Filesystem)->deleteDirectory($directory);
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
    $directory = sys_get_temp_dir().'/rocket-npm-build-'.uniqid();
    mkdir($directory);
    touch("{$directory}/.nvmrc");

    expect((new NpmInstall)->command($directory, 'npm run build'))->toEndWith('nvm use && npm run build');

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

    (new Filesystem)->deleteDirectory($root);
});
