<?php

namespace App\Commands;

use App\Actions\ChooseDatabases;
use App\Actions\FindEnvironmentAcrossTeams;
use App\Actions\ImportServerDatabase;
use App\Api\ApiErrorPresenter;
use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\WithSteps;
use App\Exceptions\ApiException;
use App\Exceptions\StepException;
use App\Schema\Operation;
use App\Schema\SchemaCache;
use App\Support\Databases;
use App\Support\PermissionGate;
use App\Support\RecordFinder;
use App\Support\Servers;
use Illuminate\Console\Command;

/**
 * Imports an environment's databases into local ones, found by slug across every team you are in.
 * Each database is dumped on the server it lives on; with several, you pick one, or take --all.
 */
class ImportDatabase extends Command
{
    use OutputsJson;
    use WithSteps;

    protected $signature = 'db:import
        {environment : The environment slug}
        {--database= : Import only this database, by name}
        {--all : Import every MySQL and PostgreSQL database of the environment}
        {--as= : Name of the local database (when importing one)}
        {--team= : Only look in this team}';

    protected $description = 'Import an environment\'s MySQL or PostgreSQL databases locally';

    public function handle(): int
    {
        if (blank(config('rocketeers.api_token'))) {
            throw ApiErrorPresenter::missingToken();
        }

        $slug = (string) $this->argument('environment');
        ['team' => $team, 'environment' => $environment] = (new FindEnvironmentAcrossTeams)($slug, $this->canPrompt(), $this->option('team'));

        $pathValues = ['team' => (string) $team['slug'], 'environment' => (string) $environment['id']];
        $databases = collect(app(RecordFinder::class)->all($this->operation('api.team.environments.databases.index', $team), $pathValues));

        [$importable, $skipped] = $databases->partition(fn (array $database): bool => Databases::isImportable($database));

        if (! $this->wantsJson() && $skipped->isNotEmpty()) {
            $this->newLine();
            $this->components->warn('Skipping '.$skipped->map(fn (array $database): string => "{$database['name']} (".Databases::label($database).')')->implode(', ').': only MySQL and PostgreSQL on your own servers can be imported.');
        }

        if ($importable->isEmpty()) {
            throw new ApiException("{$environment['name']} has no MySQL or PostgreSQL database on one of your servers.", 404);
        }

        $chosen = app(ChooseDatabases::class)->handle($importable->values(), $this->canPrompt(), $this->option('database'), (bool) $this->option('all'));

        if ($chosen->count() > 1 && filled($this->option('as'))) {
            throw new ApiException('--as names one local database; leave it out when importing several.', 422);
        }

        $credentialHosts = $chosen->contains(fn (array $database): bool => Databases::engine($database) === 'mysql')
            ? $this->serverHosts($team, $pathValues)
            : [];

        $directory = $environment['directory_path'] ?? null;

        $imported = $chosen->map(function (array $database) use ($slug, $credentialHosts, $directory): array {
            $local = (string) ($this->option('as') ?: $database['name']);

            $this->step("Importing {$database['name']} from ".Databases::host($database), fn () => app(ImportServerDatabase::class)->handle($database, $slug, $credentialHosts, $local, $directory));

            return [
                'name' => $database['name'],
                'engine' => Databases::engine($database),
                'server' => Databases::host($database),
                'local' => $local,
            ];
        });

        if ($this->wantsJson()) {
            return $this->emitJson([
                'team' => $team['slug'],
                'environment' => $slug,
                'imported' => $imported->values()->all(),
                'skipped' => $skipped->map(fn (array $database): array => ['name' => $database['name'], 'type' => Databases::label($database)])->values()->all(),
            ]);
        }

        $this->newLine();

        foreach ($imported as $database) {
            $this->line("  <fg=green>✓</> {$database['name']} → local <fg=cyan>{$database['local']}</> <fg=gray>(".($database['engine'] === 'pgsql' ? 'PostgreSQL' : 'MySQL')." from {$database['server']})</>");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $team
     * @param  array<string, string>  $pathValues
     * @return array<int, string>
     */
    private function serverHosts(array $team, array $pathValues): array
    {
        $list = app(SchemaCache::class)->findByRoute('api.team.environments.servers.index');

        if ($list === null || ! app(PermissionGate::class)->allows($list, $team)) {
            return [];
        }

        return collect(app(RecordFinder::class)->all($list, $pathValues))
            ->map(fn (array $server): ?string => Servers::host($server))
            ->filter()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $team */
    private function operation(string $route, array $team): Operation
    {
        $operation = app(SchemaCache::class)->findByRoute($route)
            ?? throw new StepException('This version of the API cannot list databases. Run `rocket api:refresh`.');

        app(PermissionGate::class)->ensure($operation, $team);

        return $operation;
    }
}
