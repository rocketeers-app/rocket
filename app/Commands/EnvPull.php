<?php

namespace App\Commands;

use App\Actions\ConfigureDotEnvLocally;
use App\Actions\ConfigureWpConfigLocally;
use App\Actions\DetectRemoteSite;
use App\Actions\GetRemoteDotEnv;
use App\Actions\GetRemoteWpConfig;
use App\Actions\NotifyLocally;
use App\Actions\PutEnvLocally;
use App\Actions\PutWpConfigLocally;
use App\Commands\Concerns\ValidatesSiteArguments;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

class EnvPull extends Command
{
    use ValidatesSiteArguments, WithSteps;

    protected $signature = 'env:pull {site} {--server=}';

    protected $description = 'Pull env for site from remote server';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;

        if (! $this->validateSiteAndServer($site, $server)) {
            return self::FAILURE;
        }

        $remoteSite = (new DetectRemoteSite)($site, $server);

        $this->startProgress(2);

        if ($remoteSite->isWordPress) {
            $config = $this->step('Fetching remote wp-config.php', fn () => (new GetRemoteWpConfig)($site, $server));
            $config = (new ConfigureWpConfigLocally)($config, $site);

            $this->step('Saving wp-config.php locally', fn () => (new PutWpConfigLocally)($config, $site));
        } else {
            $name = $remoteSite->repositoryName;
            $env = $this->step('Fetching remote .env', fn () => (new GetRemoteDotEnv)($site, $server));
            $env = (new ConfigureDotEnvLocally)($env, $name);

            $this->step('Saving .env locally', fn () => (new PutEnvLocally)($env, $name));
        }

        $this->finishProgress();

        (new NotifyLocally)("Env pulled for {$site}", $this);
    }
}
