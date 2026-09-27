<?php

namespace App\Commands\Concerns;

use App\Actions\RefreshSchema;
use App\Actions\SendApiRequest;
use App\Api\Requests\CallOperation;
use App\Exceptions\StepException;
use App\Schema\Operation;
use App\Schema\SchemaCache;
use App\Support\DeploymentRenderer;
use App\Support\OutputRenderer;
use App\Support\PermissionGate;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

/**
 * Follows a deployment until it is done by polling its steps once per interval (`rocket.deployment_poll_interval`,
 * in milliseconds), drawing them with the DeploymentRenderer. A few failed polls in a row are tolerated, so a
 * network hiccup doesn't stop the follow. Ctrl+C stops following; the deployment itself keeps running. When the
 * cached schema has no steps endpoint yet, it is refreshed once before giving up.
 */
trait FollowsDeployments
{
    private bool $stopFollowing = false;

    protected function followDeployment(array $team, array $environment, string $deploymentId): int
    {
        $steps = $this->deploymentStepsOperation($team);

        $slug = (string) $environment['slug'];
        $renderer = new DeploymentRenderer($this->followOutput(), $slug, live: $this->followsLive());
        $request = CallOperation::for($steps, ['team' => (string) $team['slug'], 'environment' => (string) $environment['id'], 'deployment' => $deploymentId]);

        $state = $this->pollUntilFinished($request, $renderer, $team['name'] ?? null);

        $renderer->finish();

        return $this->reportDeployment($team, $slug, $state);
    }

    protected function deploymentStepsOperation(array $team): Operation
    {
        $cache = app(SchemaCache::class);
        $steps = $cache->findByRoute('api.team.environments.deployments.steps');

        if ($steps === null) {
            try {
                (new RefreshSchema)();
            } catch (StepException) {
            }

            $steps = $cache->findByRoute('api.team.environments.deployments.steps');
        }

        if ($steps === null) {
            throw new StepException('This version of the API cannot follow a deployment yet. Run `rocket deploy --detach` to only start one.');
        }

        app(PermissionGate::class)->ensure($steps, $team);

        return $steps;
    }

    private function pollUntilFinished(CallOperation $request, DeploymentRenderer $renderer, ?string $teamName): array
    {
        $failures = 0;
        $state = ['deployment' => [], 'steps' => []];

        while (true) {
            try {
                $response = app(SendApiRequest::class)->handle($request, teamName: $teamName);
                $failures = 0;
            } catch (StepException $exception) {
                if (++$failures >= 5) {
                    $renderer->finish();

                    throw $exception;
                }

                $this->pauseBetweenPolls();

                continue;
            }

            $state = [
                'deployment' => (array) $response->json('deployment'),
                'steps' => array_values((array) $response->json('data')),
            ];

            if (! $this->wantsJson()) {
                $renderer->update($state['deployment'], $state['steps'], (array) $response->json('servers'));
            }

            if ($renderer->isFinished($state['deployment']) || $this->stopFollowing) {
                return $state;
            }

            $this->pauseBetweenPolls();
        }
    }

    private function reportDeployment(array $team, string $slug, array $state): int
    {
        $deployment = $state['deployment'];
        $code = filled($deployment['completed_at'] ?? null) || ($this->stopFollowing && blank($deployment['failed_at'] ?? null)) ? self::SUCCESS : self::FAILURE;

        if ($this->wantsJson()) {
            app(OutputRenderer::class)->json($this, ['team' => $team['slug'], 'environment' => $slug, ...$state, 'stopped_following' => $this->stopFollowing]);

            return $code;
        }

        $this->newLine();

        match (true) {
            filled($deployment['completed_at'] ?? null) => $this->line('  <fg=green>✓</> Deployed <fg=cyan>'.$slug.'</>'.(filled($deployment['human_duration'] ?? null) ? " in {$deployment['human_duration']}" : '')),
            filled($deployment['cancelled_at'] ?? null) => $this->components->warn('The deployment was cancelled.'),
            filled($deployment['failed_at'] ?? null) => $this->components->error('The deployment failed'.(filled($deployment['failure_reason'] ?? null) ? ": {$deployment['failure_reason']}" : '.')),
            default => $this->line("  Stopped following. The deployment keeps running; follow it again with <fg=cyan>rocket deployments:follow {$slug} {$deployment['id']}</>."),
        };

        return $code;
    }

    private function followsLive(): bool
    {
        $output = $this->followOutput();

        return ! $this->wantsJson() && $output instanceof ConsoleOutputInterface && $output->isDecorated();
    }

    private function followOutput(): mixed
    {
        return $this->output instanceof OutputStyle ? $this->output->getOutput() : $this->output;
    }

    public function getSubscribedSignals(): array
    {
        return defined('SIGINT') ? [SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopFollowing = true;

        return false;
    }

    private function pauseBetweenPolls(): void
    {
        usleep(max(0, (int) config('rocketeers.deployment_poll_interval')) * 1000);
    }
}
