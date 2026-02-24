<?php

namespace App\Commands;

use App\Actions\ConfigureDotEnvLocally;
use App\Actions\GetRemoteDotEnv;
use App\Actions\ImportRemoteDatabase;
use App\Actions\IsWordPress;
use App\Actions\NotifyLocally;
use App\Actions\PutEnvLocally;
use App\Actions\RsyncSite;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

class Sync extends Command
{
    use WithSteps;

    protected $signature = 'sync {site} {--server=}';

    protected $description = 'Sync site';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;
        $isWordPress = (new IsWordPress)($site, $server);

        $this->startProgress($isWordPress ? 4 : 6);

        $this->step('Syncing files from remote', fn () => (new RsyncSite)($site, $site, $server));

        if (! $isWordPress) {
            $env = $this->step('Fetching remote .env', fn () => (new GetRemoteDotEnv)($site, $server));
            $env = (new ConfigureDotEnvLocally)($env, $site);
            $this->step('Saving .env locally', fn () => (new PutEnvLocally)($env, $site));
        }

        $importAction = new ImportRemoteDatabase;
        $credentials = $this->step('Fetching database credentials', fn () => $importAction->fetchCredentials($site, $server));
        $this->step('Preparing local database', fn () => $importAction->prepareLocalDatabase($credentials['name']));
        $this->step('Importing remote database', fn () => $importAction->importDatabase($credentials, $server));

        $this->finishProgress();

        (new NotifyLocally)("Site {$site} is now in sync.", $this);
    }
}
