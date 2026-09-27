<?php

namespace App\Commands;

use App\Actions\FindEnvironmentAcrossTeams;
use App\Actions\OpenInEditor;
use App\Actions\SendApiRequest;
use App\Actions\SummarizeEnvChanges;
use App\Api\ApiErrorPresenter;
use App\Api\Requests\CallOperation;
use App\Commands\Concerns\FollowsDeployments;
use App\Commands\Concerns\OutputsJson;
use App\Exceptions\ApiException;
use App\Exceptions\StepException;
use App\Schema\Operation;
use App\Schema\SchemaCache;
use App\Support\DeploymentRenderer;
use App\Support\PermissionGate;
use App\Support\PrivateScratchFiles;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;

use function Laravel\Prompts\confirm;

/**
 * Opens an environment's env file in your editor, saves it to every server of the environment once you close it, and
 * offers to deploy (by default only when a key read at build time changed) and follows that deployment live. The file
 * lives in a private temporary directory only while you edit; the summary names keys, never values.
 */
class EnvEdit extends Command implements SignalableCommandInterface
{
    use FollowsDeployments;
    use OutputsJson;

    protected $signature = 'env:edit
        {environment : The environment slug}
        {--deploy : Deploy after saving without asking}
        {--no-deploy : Do not deploy after saving}
        {--team= : Only look in this team}';

    protected $description = 'Edit the env file of an environment in your editor, save it and deploy';

    private ?PrivateScratchFiles $scratch = null;

    private bool $following = false;

    public function handle(): int
    {
        if (blank(config('rocketeers.api_token'))) {
            throw ApiErrorPresenter::missingToken();
        }

        if (! $this->canPrompt()) {
            throw new StepException('env:edit opens your editor; run it in a terminal.');
        }

        $slug = (string) $this->argument('environment');

        ['team' => $team, 'environment' => $environment] = (new FindEnvironmentAcrossTeams)($slug, true, $this->option('team'));

        if (! ($environment['supports_env_file'] ?? true)) {
            throw new StepException("{$slug} has no env file: WordPress keeps its configuration in wp-config.php.");
        }

        $read = $this->operation('api.team.environments.env.show', $team);
        $update = $this->operation('api.team.environments.env.update', $team);

        $this->scratch = new PrivateScratchFiles;

        try {
            $saved = $this->editAndSave($read, $update, $team, $environment);
        } finally {
            $this->scratch->delete();
        }

        if ($saved === null) {
            return self::SUCCESS;
        }

        if (! $this->reportServers($saved['servers'] ?? [])) {
            return self::FAILURE;
        }

        return $this->offerDeploy($team, $environment, array_values((array) ($saved['build_time_keys'] ?? [])));
    }

    private function operation(string $route, array $team): Operation
    {
        $operation = app(SchemaCache::class)->findOrRefresh($route)
            ?? throw new StepException('This version of the API cannot edit an env file yet. Run `rocket api:refresh`, or update the CLI with `rocket self-update`.');

        app(PermissionGate::class)->ensure($operation, $team);

        return $operation;
    }

    private function editAndSave(Operation $read, Operation $update, array $team, array $environment): ?array
    {
        $slug = (string) $environment['slug'];
        $env = $this->fetch($read, $team, $environment);
        $path = $this->scratch->write($slug.'.env', $env['contents']);

        while (true) {
            app(OpenInEditor::class)->handle($path);

            $edited = (string) file_get_contents($path);

            if ($edited === $env['contents']) {
                $this->components->info('No changes.');

                return null;
            }

            $changes = app(SummarizeEnvChanges::class)->handle($env['contents'], $edited);
            $this->showChanges($slug, $changes);

            if (! confirm(label: "Save the env of {$slug} and write it to its servers?", default: true)) {
                $this->components->warn('Nothing was saved.');

                return null;
            }

            try {
                return $this->save($update, $team, $environment, $edited, $env['checksum']);
            } catch (ApiException $exception) {
                if (! in_array($exception->status, [409, 422], true)) {
                    throw $exception;
                }

                $this->reportRefusal($exception, $changes);

                if (! confirm(label: $exception->status === 409 ? 'Open the editor again with the current env?' : 'Open the editor again with your changes?', default: true)) {
                    return null;
                }

                if ($exception->status === 409) {
                    $env = $this->fetch($read, $team, $environment);
                    $this->scratch->write($slug.'.env', $env['contents']);
                }
            }
        }
    }

    /** @return array{contents: string, checksum: string} */
    private function fetch(Operation $read, array $team, array $environment): array
    {
        $data = (array) app(SendApiRequest::class)->handle(
            CallOperation::for($read, ['team' => (string) $team['slug'], 'environment' => (string) $environment['id']]),
            teamName: $team['name'] ?? null,
        )->json('data');

        return ['contents' => (string) ($data['contents'] ?? ''), 'checksum' => (string) ($data['checksum'] ?? '')];
    }

    private function save(Operation $update, array $team, array $environment, string $contents, string $checksum): array
    {
        return (array) app(SendApiRequest::class)->handle(
            CallOperation::for($update, ['team' => (string) $team['slug'], 'environment' => (string) $environment['id']], body: ['contents' => $contents, 'if_match' => $checksum]),
            teamName: $team['name'] ?? null,
        )->json('data');
    }

    /** @param array{added: array<int, string>, changed: array<int, string>, removed: array<int, string>} $changes */
    private function showChanges(string $slug, array $changes): void
    {
        $this->newLine();
        $this->line("  Changes to <fg=cyan>{$slug}</>:");

        foreach (['added' => '<fg=green>+</>', 'changed' => '<fg=yellow>~</>', 'removed' => '<fg=red>-</>'] as $kind => $marker) {
            foreach ($changes[$kind] as $key) {
                $this->line("  {$marker} {$key}");
            }
        }

        if (app(SummarizeEnvChanges::class)->keys($changes) === []) {
            $this->line('  <fg=gray>Only comments, blank lines or formatting</>');
        }

        $this->newLine();
    }

    /** @param array{added: array<int, string>, changed: array<int, string>, removed: array<int, string>} $changes */
    private function reportRefusal(ApiException $exception, array $changes): void
    {
        $this->newLine();

        if ($exception->status === 409) {
            $this->components->error('Someone changed the env since you opened it; nothing was saved.');

            $keys = app(SummarizeEnvChanges::class)->keys($changes);

            if ($keys !== []) {
                $this->line('  You had changed: '.implode(', ', $keys));
                $this->newLine();
            }

            return;
        }

        $this->components->error($exception->getMessage());

        foreach (array_merge(...array_values($exception->errors)) as $message) {
            $this->line('  <fg=red>•</> '.OutputFormatter::escape($message));
        }

        $this->newLine();
    }

    /** @param array<int, array{name?: string, ok?: bool, error?: ?string}> $servers */
    private function reportServers(array $servers): bool
    {
        foreach ($servers as $server) {
            $this->line(DeploymentRenderer::taskLine('Saving on '.($server['name'] ?? 'server'), ($server['ok'] ?? false) ? '<fg=green;options=bold>DONE</>' : '<fg=red;options=bold>FAIL</>'));

            if (! ($server['ok'] ?? false) && filled($server['error'] ?? null)) {
                $this->line('  <fg=red>'.OutputFormatter::escape((string) $server['error']).'</>');
            }
        }

        $failed = array_values(array_filter($servers, fn (array $server): bool => ! ($server['ok'] ?? false)));

        if ($failed !== []) {
            $this->components->error('The env is saved, but '.implode(', ', array_column($failed, 'name')).' did not get it. Fix the server and save again from Rocketeers.');

            return false;
        }

        return true;
    }

    /** @param array<int, string> $buildTimeKeys */
    private function offerDeploy(array $team, array $environment, array $buildTimeKeys): int
    {
        $slug = (string) $environment['slug'];

        if ($buildTimeKeys !== []) {
            $this->newLine();
            $this->line('  '.implode(', ', $buildTimeKeys).' '.(count($buildTimeKeys) === 1 ? 'is' : 'are').' read at build time, so '.(count($buildTimeKeys) === 1 ? 'it takes' : 'they take').' effect after a deploy.');
        }

        $deploy = match (true) {
            (bool) $this->option('deploy') => true,
            (bool) $this->option('no-deploy') => false,
            default => confirm(label: "Deploy {$slug} now?", default: $buildTimeKeys !== []),
        };

        if (! $deploy) {
            $this->components->info($buildTimeKeys === [] ? "The env of {$slug} is live." : "Saved. Deploy later with `rocket deploy {$slug}`.");

            return self::SUCCESS;
        }

        $operation = $this->deployOperation($team);
        $deployment = $this->startDeployment($operation, $team, $environment);
        $this->following = true;

        return $this->followDeployment($team, $environment, (string) $deployment['id']);
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if ($this->following) {
            $this->stopFollowing = true;

            return false;
        }

        $this->scratch?->delete();

        return 130;
    }
}
