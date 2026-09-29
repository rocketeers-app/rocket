<?php

namespace App\Commands;

use App\Actions\FindEnvironmentAcrossTeams;
use App\Actions\SendApiRequest;
use App\Api\Requests\CallOperation;
use App\Commands\Concerns\FollowsDeployments;
use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\ResolvesTeam;
use App\Exceptions\StepException;
use App\Schema\SchemaCache;
use App\Support\PermissionGate;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;

use function Laravel\Prompts\confirm;

/** Follows a deployment of an environment live until it is done: the one you name, else the latest one, else — when you say so — a new one. */
class DeploymentsFollow extends Command implements SignalableCommandInterface
{
    use FollowsDeployments;
    use OutputsJson;
    use ResolvesTeam;

    protected $signature = 'deployments:follow
        {environment? : The environment slug; asked for when left out}
        {deployment? : The deployment id; the latest deployment when left out}
        {--team= : Only look in this team}';

    protected $description = 'Follow a deployment live until it is done';

    public function handle(): int
    {
        $this->ensureToken();

        ['team' => $team, 'environment' => $environment] = (new FindEnvironmentAcrossTeams)($this->argument('environment'), $this->canPrompt(), $this->teamFilter());
        $slug = (string) $environment['slug'];

        $deploymentId = filled($this->argument('deployment'))
            ? (string) $this->argument('deployment')
            : $this->latestDeployment($team, $environment, $slug);

        if ($deploymentId === null) {
            if (! confirm(label: "{$slug} has no deployments yet. Deploy it now?", default: true)) {
                $this->components->info("Nothing to follow. Deploy it later with `rocket deploy {$slug}`.");

                return self::SUCCESS;
            }

            $deploymentId = (string) $this->startDeployment($this->deployOperation($team), $team, $environment)['id'];
        }

        return $this->followDeployment($team, $environment, $deploymentId);
    }

    private function latestDeployment(array $team, array $environment, string $slug): ?string
    {
        $list = app(SchemaCache::class)->findByRoute('api.team.environments.deployments.index')
            ?? throw new StepException('This version of the API cannot list deployments. Run `rocket api:refresh`.');

        app(PermissionGate::class)->ensure($list, $team);

        $latest = app(SendApiRequest::class)->handle(
            CallOperation::for($list, ['team' => (string) $team['slug'], 'environment' => (string) $environment['id']], ['per_page' => 1]),
            teamName: $team['name'] ?? null,
        )->json('data.0.id');

        if (filled($latest)) {
            return (string) $latest;
        }

        return $this->canPrompt() ? null : throw new StepException("{$slug} has no deployments yet. Start one with `rocket deploy {$slug}`.");
    }
}
