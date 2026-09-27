<?php

namespace App\Commands;

use App\Actions\FindEnvironmentAcrossTeams;
use App\Actions\SendApiRequest;
use App\Api\ApiErrorPresenter;
use App\Api\Requests\CallOperation;
use App\Commands\Concerns\FollowsDeployments;
use App\Commands\Concerns\OutputsJson;
use App\Exceptions\StepException;
use App\Schema\SchemaCache;
use App\Support\PermissionGate;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;

/**
 * Deploys an environment, found by slug across every team you are in, and follows the deployment live until it is
 * done: every step on every server, the running ones as they run. With --detach it only starts the deployment.
 */
class Deploy extends Command implements SignalableCommandInterface
{
    use FollowsDeployments;
    use OutputsJson;

    protected $signature = 'deploy
        {environment : The environment slug}
        {--detach : Start the deployment without following it}
        {--team= : Only look in this team}';

    protected $description = 'Deploy an environment and follow it live';

    public function handle(): int
    {
        if (blank(config('rocketeers.api_token'))) {
            throw ApiErrorPresenter::missingToken();
        }

        $slug = (string) $this->argument('environment');

        ['team' => $team, 'environment' => $environment] = (new FindEnvironmentAcrossTeams)($slug, $this->canPrompt(), $this->option('team'));

        $deploy = app(SchemaCache::class)->findByRoute('api.team.environments.deploy')
            ?? throw new StepException('This version of the API cannot deploy. Run `rocket api:refresh`.');

        app(PermissionGate::class)->ensure($deploy, $team);

        if (! $this->option('detach')) {
            $this->deploymentStepsOperation($team);
        }

        $deployment = (array) app(SendApiRequest::class)->handle(
            CallOperation::for($deploy, ['team' => (string) $team['slug'], 'environment' => (string) $environment['id']]),
            teamName: $team['name'] ?? null,
        )->json('data');

        if (! $this->option('detach')) {
            return $this->followDeployment($team, $environment, (string) $deployment['id']);
        }

        if ($this->wantsJson()) {
            return $this->emitJson(['team' => $team['slug'], 'environment' => $slug, 'deployment' => $deployment]);
        }

        $this->components->info("Deploying {$slug}. Follow it with `rocket deployments:follow {$slug}`.");

        return self::SUCCESS;
    }
}
