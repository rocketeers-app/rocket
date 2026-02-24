<?php

namespace App\Commands;

use App\Actions\ComposerInstall;
use App\Actions\ConfigureDotEnvLocally;
use App\Actions\GetRemoteDotEnv;
use App\Actions\GetRepositoryName;
use App\Actions\ImportRemoteDatabase;
use App\Actions\NotifyLocally;
use App\Actions\NpmInstall;
use App\Actions\PutEnvLocally;
use App\Actions\RsyncSite;
use App\Actions\RunMigrations;
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

        $this->startProgress(8);

        $name = $this->step('Fetching repository name', fn () => (new GetRepositoryName)($site, $server));

        $this->step('Syncing files from remote', fn () => (new RsyncSite)($name, $site, $server));

        $env = $this->step('Fetching remote .env', fn () => (new GetRemoteDotEnv)($site, $server));
        $env = (new ConfigureDotEnvLocally)($env, $name);
        $this->step('Saving .env locally', fn () => (new PutEnvLocally)($env, $name));

        $importAction = new ImportRemoteDatabase;
        $credentials = $this->step('Fetching database credentials', fn () => $importAction->fetchCredentials($site, $server));
        $this->step('Preparing local database', fn () => $importAction->prepareLocalDatabase($credentials['name']));
        $this->step('Importing remote database', fn () => $importAction->importDatabase($credentials, $server));

        $this->step('Running composer install', fn () => (new ComposerInstall)($name));

        $this->finishProgress();

        (new NotifyLocally)("Site {$site} is now in sync.", $this);
    }
}
