<?php

namespace App\Commands;

use App\Actions\ChangeWorkingDirectory;
use App\Actions\CheckoutBranchLocally;
use App\Actions\ComposerInstall;
use App\Actions\ConfigureDotEnvLocally;
use App\Actions\GetCurrentBranch;
use App\Actions\GetRemoteDotEnv;
use App\Actions\GetRepositoryName;
use App\Actions\GetRepositoryUrl;
use App\Actions\GitCloneRepository;
use App\Actions\ImportRemoteDatabase;
use App\Actions\IsWordPress;
use App\Actions\IsolatePhpVersion;
use App\Actions\NpmInstall;
use App\Actions\PutEnvLocally;
use App\Actions\RunMigrations;
use App\Actions\SecureSite;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

class Install extends Command
{
    use WithSteps;

    protected $signature = 'install {site} {--server=} {--php=8.0}';

    protected $description = 'Install site';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;
        $phpVersion = $this->option('php');

        if ((new IsWordPress)($site, $server)) {
            return $this->call('sync', [
                'site' => $site,
                '--server' => $server,
            ]);
        }

        $this->startProgress(14);

        $url = $this->step('Fetching repository URL', fn () => (new GetRepositoryUrl)($site, $server));
        $name = $this->step('Fetching repository name', fn () => (new GetRepositoryName)($site, $server));
        $branch = $this->step('Fetching current branch', fn () => (new GetCurrentBranch)($site, $server));

        (new ChangeWorkingDirectory)($name);

        $this->step('Cloning repository', fn () => (new GitCloneRepository)($name, $url));
        $this->step('Checking out branch', fn () => (new CheckoutBranchLocally)($name, $branch));
        $this->step('Isolating PHP version', fn () => (new IsolatePhpVersion)($name, $phpVersion));

        $env = $this->step('Fetching remote .env', fn () => (new GetRemoteDotEnv)($site, $server));
        $env = (new ConfigureDotEnvLocally)($env, $name);
        (new PutEnvLocally)($env, $name);

        $importAction = new ImportRemoteDatabase;
        $credentials = $this->step('Fetching database credentials', fn () => $importAction->fetchCredentials($site, $server));
        $this->step('Preparing local database', fn () => $importAction->prepareLocalDatabase($credentials['name']));
        $this->step('Importing remote database', fn () => $importAction->importDatabase($credentials, $server));

        $this->step('Running composer install', fn () => (new ComposerInstall)($name));
        $this->step('Running migrations', fn () => (new RunMigrations)($name));
        $this->step('Running npm install', fn () => (new NpmInstall)($name));
        $this->step('Securing site', fn () => (new SecureSite)($name));

        $this->finishProgress();

        $this->line('');
        $this->info("Site available at: https://{$name}.test");
    }
}
