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

/** Follows a deployment of an environment live until it is done: the one you name, else the latest one. */
class DeploymentsFollow extends Command implements SignalableCommandInterface
{
    use FollowsDeployments;
    use OutputsJson;

    protected $signature = 'deployments:follow
        {environment : The environment slug}
        {deployment? : The deployment id; the latest deployment when left out}
        {--team= : Only look in this team}';

    protected $description = 'Follow a deployment live until it is done';

    public function handle(): int
    {
        if (blank(config('rocketeers.api_token'))) {
            throw ApiErrorPresenter::missingToken();
        }

        $slug = (string) $this->argument('environment');

        ['team' => $team, 'environment' => $environment] = (new FindEnvironmentAcrossTeams)($slug, $this->canPrompt(), $this->option('team'));

        $deploymentId = filled($this->argument('deployment'))
            ? (string) $this->argument('deployment')
            : $this->latestDeployment($team, $environment, $slug);

        return $this->followDeployment($team, $environment, $deploymentId);
    }

    private function latestDeployment(array $team, array $environment, string $slug): string
    {
        $list = app(SchemaCache::class)->findByRoute('api.team.environments.deployments.index')
            ?? throw new StepException('This version of the API cannot list deployments. Run `rocket api:refresh`.');

        app(PermissionGate::class)->ensure($list, $team);

        $latest = app(SendApiRequest::class)->handle(
            CallOperation::for($list, ['team' => (string) $team['slug'], 'environment' => (string) $environment['id']], ['per_page' => 1]),
            teamName: $team['name'] ?? null,
        )->json('data.0.id');

        return filled($latest) ? (string) $latest : throw new StepException("{$slug} has no deployments yet. Start one with `rocket deploy {$slug}`.");
    }
}
