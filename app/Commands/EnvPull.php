<?php

namespace App\Commands;

use App\Actions\ConfigureDotEnvLocally;
use App\Actions\ConfigureWpConfigLocally;
use App\Actions\FindConnectedServer;
use App\Actions\FindEnvironmentAcrossTeams;
use App\Actions\NotifyLocally;
use App\Actions\PutEnvLocally;
use App\Actions\PutWpConfigLocally;
use App\Actions\ReadRemoteEnvFile;
use App\Api\ApiErrorPresenter;
use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

/** Pulls an environment's .env (or wp-config.php) from its first connected server, found by slug across every team you are in. */
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
        $server = $this->step('Finding a connected server', fn (): string => app(FindConnectedServer::class)->handle($team, $environment));
        $file = $this->step('Fetching the remote env file', fn (): array => app(ReadRemoteEnvFile::class)->handle($server, $site, $environment['directory_path'] ?? null));

        $this->step('Saving it locally', function () use ($site, $file): void {
            if ($file['wordpress']) {
                app(PutWpConfigLocally::class)->handle(app(ConfigureWpConfigLocally::class)->handle($file['contents'], $site), $site);

                return;
            }

            app(PutEnvLocally::class)->handle(app(ConfigureDotEnvLocally::class)->handle($file['contents'], $file['repository']), $file['repository']);
        });

        $this->finishProgress();

        if ($this->wantsJson()) {
            return $this->emitJson(['team' => $team['slug'], 'environment' => $site, 'server' => $server, 'wordpress' => $file['wordpress']]);
        }

        (new NotifyLocally)("Env pulled for {$site} from {$server}", $this);

        return self::SUCCESS;
    }
}
