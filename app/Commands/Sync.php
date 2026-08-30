<?php

namespace App\Commands;

use App\Actions\ConfigureDotEnvLocally;
use App\Actions\ConfigureWpConfigLocally;
use App\Actions\GetRemoteDotEnv;
use App\Actions\GetRemoteWpConfig;
use App\Actions\GetRepositoryName;
use App\Actions\ImportRemoteDatabase;
use App\Actions\IsBedrock;
use App\Actions\IsWordPress;
use App\Actions\NotifyLocally;
use App\Actions\PutEnvLocally;
use App\Actions\PutWpConfigLocally;
use App\Actions\RsyncSite;
use App\Actions\SecureSite;
use App\Commands\Concerns\ValidatesSiteArguments;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

class Sync extends Command
{
    use ValidatesSiteArguments, WithSteps;

    protected $signature = 'sync {site} {--server=}';

    protected $description = 'Sync site';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;

        if (! $this->validateSiteAndServer($site, $server)) {
            return self::FAILURE;
        }

        $isWordPress = (new IsWordPress)($site, $server);

        $this->startProgress(8);

        $name = $this->step('Fetching repository name', fn () => (new GetRepositoryName)($site, $server));

        $this->step('Syncing files from remote', fn () => (new RsyncSite)($name, $site, $server));

        if ($isWordPress && ! (new IsBedrock)($site, $server)) {
            $config = $this->step('Fetching remote wp-config.php', fn () => (new GetRemoteWpConfig)($site, $server));
            $config = (new ConfigureWpConfigLocally)($config, $name);
            $this->step('Saving wp-config.php locally', fn () => (new PutWpConfigLocally)($config, $name));
        } else {
            $env = $this->step('Fetching remote .env', fn () => (new GetRemoteDotEnv)($site, $server));
            $env = (new ConfigureDotEnvLocally)($env, $name);
            $this->step('Saving .env locally', fn () => (new PutEnvLocally)($env, $name));
        }

        $importAction = new ImportRemoteDatabase;
        $credentials = $this->step('Fetching database credentials', fn () => $importAction->fetchCredentials($site, $server));
        $this->step('Preparing local database', fn () => $importAction->prepareLocalDatabase($credentials['name']));
        $this->step('Importing remote database', fn () => $importAction->importDatabase($credentials, $server));

        $this->step('Securing site', fn () => (new SecureSite)($name));

        $this->finishProgress();

        (new NotifyLocally)("Site {$site} is now in sync.", $this);

        $this->line('');
        $this->info("View in browser: https://{$name}.test");
    }
}
