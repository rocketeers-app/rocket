<?php

namespace App\Commands;

use App\Actions\FindEnvironmentAcrossTeams;
use App\Commands\Concerns\FollowsDeployments;
use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\ResolvesTeam;
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
    use ResolvesTeam;

    protected $signature = 'deploy
        {environment? : The environment slug; asked for when left out}
        {--detach : Start the deployment without following it}
        {--team= : Only look in this team}';

    protected $description = 'Deploy an environment and follow it live';

    public function handle(): int
    {
        $this->ensureToken();

        ['team' => $team, 'environment' => $environment] = (new FindEnvironmentAcrossTeams)($this->argument('environment'), $this->canPrompt(), $this->teamFilter());
        $slug = (string) $environment['slug'];

        $deploy = $this->deployOperation($team, follow: ! $this->option('detach'));
        $deployment = $this->startDeployment($deploy, $team, $environment);

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
