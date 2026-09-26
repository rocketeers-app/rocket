<?php

namespace App\Commands;

use App\Actions\ConfigureDotEnvLocally;
use App\Actions\ConfigureWpConfigLocally;
use App\Actions\FindEnvironmentAcrossTeams;
use App\Actions\GetRemoteDotEnv;
use App\Actions\GetRemoteWpConfig;
use App\Actions\GetRepositoryName;
use App\Actions\IsWordPress;
use App\Actions\ListEnvironmentHosts;
use App\Actions\NotifyLocally;
use App\Actions\PutEnvLocally;
use App\Actions\PutWpConfigLocally;
use App\Api\ApiErrorPresenter;
use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\WithSteps;
use App\Exceptions\StepException;
use Illuminate\Console\Command;

/** Pulls an environment's .env (or wp-config.php) from its server, found by slug across every team you are in. */
class EnvPull extends Command
{
    use OutputsJson;
    use WithSteps;

    protected $signature = 'env:pull
        {environment : The environment slug}
        {--team= : Only look in this team}';

    protected $description = 'Pull the env file of an environment from its server';

    public function handle(): int
    {
        if (blank(config('rocketeers.api_token'))) {
            throw ApiErrorPresenter::missingToken();
        }

        $site = (string) $this->argument('environment');

        $this->startProgress(4);

        ['team' => $team, 'environment' => $environment] = $this->step('Finding the environment', fn (): array => (new FindEnvironmentAcrossTeams)($site, $this->canPrompt(), $this->option('team')));
        $hosts = $this->step('Finding its servers', fn (): array => app(ListEnvironmentHosts::class)->handle($team, $environment));

        [$server, $isWordPress, $contents] = $this->step('Fetching the remote env file', fn (): array => $this->fetch($site, $hosts));

        $this->step('Saving it locally', function () use ($site, $server, $isWordPress, $contents): void {
            if ($isWordPress) {
                app(PutWpConfigLocally::class)->handle(app(ConfigureWpConfigLocally::class)->handle($contents, $site), $site);

                return;
            }

            $name = app(GetRepositoryName::class)->handle($site, $server);
            app(PutEnvLocally::class)->handle(app(ConfigureDotEnvLocally::class)->handle($contents, $name), $name);
        });

        $this->finishProgress();

        if ($this->wantsJson()) {
            return $this->emitJson(['team' => $team['slug'], 'environment' => $site, 'server' => $server, 'wordpress' => $isWordPress]);
        }

        (new NotifyLocally)("Env pulled for {$site} from {$server}", $this);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $hosts
     * @return array{0: string, 1: bool, 2: string}
     */
    private function fetch(string $site, array $hosts): array
    {
        foreach ($hosts as $host) {
            try {
                $isWordPress = app(IsWordPress::class)->handle($site, $host);
                $contents = $isWordPress
                    ? app(GetRemoteWpConfig::class)->handle($site, $host)
                    : app(GetRemoteDotEnv::class)->handle($site, $host);

                return [$host, $isWordPress, $contents];
            } catch (StepException) {
                continue;
            }
        }

        throw new StepException("None of the servers of {$site} (".implode(', ', $hosts).') has its env file.');
    }
}
