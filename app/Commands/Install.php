<?php

namespace App\Commands;

use App\Actions\ChooseDatabases;
use App\Actions\ComposerInstall;
use App\Actions\ConfigureDotEnvLocally;
use App\Actions\ConfigureWpConfigLocally;
use App\Actions\FindConnectedServer;
use App\Actions\FindEnvironmentAcrossTeams;
use App\Actions\GetRemoteRepositoryUrl;
use App\Actions\ImportServerDatabase;
use App\Actions\IsolatePhpVersion;
use App\Actions\LocalProjectName;
use App\Actions\LocalRepositoryName;
use App\Actions\NpmBuild;
use App\Actions\NpmInstall;
use App\Actions\ParkDirectory;
use App\Actions\PrepareLocalRepository;
use App\Actions\PutEnvLocally;
use App\Actions\PutWpConfigLocally;
use App\Actions\ReadRemoteEnvFile;
use App\Actions\RunMigrations;
use App\Actions\SecureSite;
use App\Actions\SendApiRequest;
use App\Actions\SetEnvValues;
use App\Api\ApiErrorPresenter;
use App\Api\Requests\CallOperation;
use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\WithSteps;
use App\Exceptions\StepException;
use App\Schema\SchemaCache;
use App\Support\Databases;
use App\Support\PermissionGate;
use App\Support\RecordFinder;
use Dotenv\Dotenv;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

/**
 * Installs an environment locally, found by slug across every team you are in: clones (or updates) its
 * repository into {projects_path}/{name}, pulls its env file from the first connected server, imports its
 * databases, isolates its PHP version, installs its dependencies, migrates and secures https://{name}.test.
 * An environment in a monorepo (a root directory like `apps/api`) clones the whole repository into
 * {projects_path}/{repository}, installs in its root directory, named after that directory's last part,
 * and parks the directory around it in Herd.
 */
class Install extends Command
{
    use OutputsJson;
    use WithSteps;

    protected $signature = 'install
        {environment : The environment slug}
        {--database= : Import only this database, by name (with --all: the main connection)}
        {--all : Import every MySQL and PostgreSQL database of the environment}
        {--team= : Only look in this team}';

    protected $description = 'Install an environment locally';

    public function handle(): int
    {
        if (blank(config('rocketeers.api_token'))) {
            throw ApiErrorPresenter::missingToken();
        }

        $slug = (string) $this->argument('environment');

        ['team' => $team, 'environment' => $found] = (new FindEnvironmentAcrossTeams)($slug, $this->canPrompt(), $this->option('team'));
        $environment = $this->details($team, $found);
        $pathValues = ['team' => (string) $team['slug'], 'environment' => (string) $environment['id']];

        $name = app(LocalProjectName::class)->handle($environment);
        $remoteDirectory = $environment['directory_path'] ?? null;

        $server = app(FindConnectedServer::class)->handle($team, $environment);
        $url = $environment['repository']['ssh_url'] ?? app(GetRemoteRepositoryUrl::class)->handle($server, $slug, $remoteDirectory);
        $branch = filled($environment['branch'] ?? null) ? (string) $environment['branch'] : null;

        $rootDirectory = trim((string) ($environment['root_directory'] ?? ''), '/') ?: null;
        $projectsPath = rtrim((string) config('rocketeers.projects_path'), '/');
        $repositoryDirectory = $projectsPath.'/'.($rootDirectory === null ? $name : app(LocalRepositoryName::class)->handle($url));
        $directory = $rootDirectory === null ? $repositoryDirectory : "{$repositoryDirectory}/{$rootDirectory}";

        $file = ($environment['supports_env_file'] ?? true)
            ? app(ReadRemoteEnvFile::class)->handle($server, $slug, $remoteDirectory)
            : null;

        ['databases' => $databases, 'main' => $main] = $this->chooseDatabases($team, $pathValues, $file);

        $switch = $rootDirectory === null || $this->shouldSwitchBranch($repositoryDirectory, $branch);
        $stash = $switch ? $this->shouldStash($repositoryDirectory) : null;

        if ($stash === null) {
            $this->components->warn("Stopped: nothing in {$repositoryDirectory} was changed.");

            return self::FAILURE;
        }

        $steps = [
            [$branch === null ? 'Preparing the repository' : "Preparing the repository on {$branch}", fn () => app(PrepareLocalRepository::class)->handle($repositoryDirectory, $url, $branch, $stash)],
        ];

        if ($file !== null) {
            $steps[] = ['Writing the local env file', fn () => $this->writeEnv($file, $name, $directory, $main)];
        }

        $imported = $databases->map(fn (array $database): array => [
            'name' => $database['name'],
            'engine' => Databases::engine($database),
            'server' => Databases::host($database),
            'local' => $database === $main ? $name : (string) $database['name'],
            'main' => $database === $main,
        ])->values();

        foreach ($imported as $index => $database) {
            $steps[] = ["Importing {$database['name']} from {$database['server']}", fn () => app(ImportServerDatabase::class)->handle($databases->values()[$index], $slug, [$server], $database['local'], $remoteDirectory)];
        }

        $steps = [...$steps, ...$this->projectSteps($environment, $name, $directory, $repositoryDirectory, $rootDirectory !== null)];

        $this->startProgress(count($steps));

        foreach ($steps as [$message, $callback]) {
            $this->step($message, $callback);
        }

        $this->finishProgress();

        $siteUrl = "https://{$name}.test";

        if ($this->wantsJson()) {
            return $this->emitJson([
                'team' => $team['slug'],
                'environment' => $slug,
                'path' => $directory,
                'repository_path' => $repositoryDirectory,
                'url' => $siteUrl,
                'databases' => $imported->all(),
            ]);
        }

        $this->newLine();

        foreach ($imported as $database) {
            $this->line("  <fg=green>✓</> {$database['name']} → local <fg=cyan>{$database['local']}</> <fg=gray>(".($database['engine'] === 'pgsql' ? 'PostgreSQL' : 'MySQL')." from {$database['server']}".($database['main'] ? ', main connection' : '').')</>');
        }

        $this->line("  <fg=green>✓</> Installed in <fg=cyan>{$directory}</>");
        $this->newLine();
        $this->info("View in browser: {$siteUrl}");

        return self::SUCCESS;
    }

    private function details(array $team, array $environment): array
    {
        $show = app(SchemaCache::class)->findByRoute('api.team.environments.show')
            ?? throw new StepException('This version of the API cannot read an environment. Run `rocket api:refresh`.');

        app(PermissionGate::class)->ensure($show, $team);

        $response = app(SendApiRequest::class)->handle(CallOperation::for($show, ['team' => (string) $team['slug'], 'environment' => (string) $environment['id']]), teamName: $team['name'] ?? null);

        return [...$environment, ...(array) $response->json('data')];
    }

    private function chooseDatabases(array $team, array $pathValues, ?array $file): array
    {
        $list = app(SchemaCache::class)->findByRoute('api.team.environments.databases.index')
            ?? throw new StepException('This version of the API cannot list databases. Run `rocket api:refresh`.');

        app(PermissionGate::class)->ensure($list, $team);

        [$importable, $skipped] = collect(app(RecordFinder::class)->all($list, $pathValues))
            ->partition(fn (array $database): bool => Databases::isImportable($database));

        if (! $this->wantsJson() && $skipped->isNotEmpty()) {
            $this->components->warn('Skipping '.$skipped->map(fn (array $database): string => "{$database['name']} (".Databases::label($database).')')->implode(', ').': only MySQL and PostgreSQL on your own servers can be imported.');
        }

        if (! $this->wantsJson() && $importable->isEmpty()) {
            $this->components->warn('No MySQL or PostgreSQL database on one of your servers to import.');
        }

        return app(ChooseDatabases::class)->forInstall(
            $importable->values(),
            $this->canPrompt(),
            $this->option('database'),
            (bool) $this->option('all'),
            $file === null ? null : $this->remoteDatabaseName($file),
        );
    }

    private function remoteDatabaseName(array $file): ?string
    {
        if ($file['wordpress']) {
            return preg_match('/define\s*\(\s*[\'"]DB_NAME[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)/', $file['contents'], $match) === 1 ? $match[1] : null;
        }

        return Dotenv::parse($file['contents'])['DB_DATABASE'] ?? null;
    }

    private function shouldSwitchBranch(string $repositoryDirectory, ?string $branch): bool
    {
        $current = $branch === null ? null : app(PrepareLocalRepository::class)->currentBranch($repositoryDirectory);

        if ($current === null || $current === $branch) {
            return true;
        }

        if (! $this->canPrompt()) {
            throw new StepException("{$repositoryDirectory} is on {$current}, not {$branch}. Switch it yourself, or run `rocket install` interactively to switch it.");
        }

        return confirm(label: "{$repositoryDirectory} is on {$current}. Switch it to {$branch}? This affects every app in the repository.", default: false);
    }

    private function shouldStash(string $directory): ?bool
    {
        $repository = app(PrepareLocalRepository::class);

        if (! $repository->isDirty($directory)) {
            return false;
        }

        if (! $this->canPrompt()) {
            throw new StepException("{$directory} has local changes. Commit or stash them first, or run `rocket install` interactively to stash them.");
        }

        return confirm(label: "{$directory} has local changes. Stash your local changes?", default: true) ? true : null;
    }

    private function writeEnv(array $file, string $name, string $directory, ?array $main): string
    {
        if ($file['wordpress']) {
            return app(PutWpConfigLocally::class)->handle(app(ConfigureWpConfigLocally::class)->handle($file['contents'], $name), $name, $directory);
        }

        $engine = $main === null ? null : Databases::engine($main);
        $env = app(ConfigureDotEnvLocally::class)->handle($file['contents'], $name, $engine);

        if ($engine !== null) {
            $env = app(SetEnvValues::class)->handle($env, ['DB_CONNECTION' => $engine]);
        }

        return app(PutEnvLocally::class)->handle($env, $name, $directory);
    }

    private function projectSteps(array $environment, string $name, string $directory, string $repositoryDirectory, bool $inMonorepo): array
    {
        $steps = [];
        $phpVersion = $environment['php_version'] ?? null;

        if ($inMonorepo) {
            $parent = dirname($directory);
            $steps[] = ["Parking {$parent} in Herd", fn () => app(ParkDirectory::class)->handle($parent)];
        }

        if (($environment['is_php_based'] ?? false) && filled($phpVersion)) {
            $steps[] = ["Isolating PHP {$phpVersion}", fn () => app(IsolatePhpVersion::class)->handle($name, (string) $phpVersion, $directory)];
        }

        $steps[] = ['Running composer install', fn () => file_exists("{$directory}/composer.json") ? app(ComposerInstall::class)->handle($name, $directory) : null];
        $steps[] = ['Running migrations', fn () => file_exists("{$directory}/artisan") ? app(RunMigrations::class)->handle($name, $directory) : null];
        $steps[] = ['Running npm install', fn () => file_exists("{$directory}/package.json") ? app(NpmInstall::class)->handle($name, $this->usesWorkspaces($repositoryDirectory) ? $repositoryDirectory : $directory) : null];
        $steps[] = ['Building frontend assets', fn () => file_exists("{$directory}/package.json") ? app(NpmBuild::class)->handle($directory) : null];
        $steps[] = ['Securing the site', fn () => app(SecureSite::class)->handle($name, $directory)];

        return $steps;
    }

    private function usesWorkspaces(string $repositoryDirectory): bool
    {
        if (! file_exists("{$repositoryDirectory}/package.json")) {
            return false;
        }

        $package = json_decode((string) file_get_contents("{$repositoryDirectory}/package.json"), true);

        return is_array($package) && filled($package['workspaces'] ?? null);
    }
}
