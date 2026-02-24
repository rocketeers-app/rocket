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
        $importAction = new ImportRemoteDatabase;

        $this->registerSteps([
            'Fetching repository URL' => fn () => (new GetRepositoryUrl)($site, $server),
            'Fetching repository name' => fn () => (new GetRepositoryName)($site, $server),
            'Fetching current branch' => fn () => (new GetCurrentBranch)($site, $server),
            'Cloning repository' => function ($results) {
                (new ChangeWorkingDirectory)($results['Fetching repository name']);
                (new GitCloneRepository)($results['Fetching repository name'], $results['Fetching repository URL']);
            },
            'Checking out branch' => function ($results) {
                (new CheckoutBranchLocally)($results['Fetching repository name'], $results['Fetching current branch']);
            },
            'Isolating PHP version' => function ($results) use ($phpVersion) {
                (new IsolatePhpVersion)($results['Fetching repository name'], $phpVersion);
            },
            'Configuring .env' => function ($results) use ($site, $server) {
                $env = (new GetRemoteDotEnv)($site, $server);
                $env = (new ConfigureDotEnvLocally)($env, $results['Fetching repository name']);
                (new PutEnvLocally)($env, $results['Fetching repository name']);
            },
            'Fetching database credentials' => fn () => $importAction->fetchCredentials($site, $server),
            'Preparing local database' => function ($results) use ($importAction) {
                $importAction->prepareLocalDatabase($results['Fetching database credentials']['name']);
            },
            'Importing remote database' => function ($results) use ($importAction, $server) {
                $importAction->importDatabase($results['Fetching database credentials'], $server);
            },
            'Running composer install' => function ($results) {
                (new ComposerInstall)($results['Fetching repository name']);
            },
            'Running migrations' => function ($results) {
                (new RunMigrations)($results['Fetching repository name']);
            },
            'Running npm install' => function ($results) {
                (new NpmInstall)($results['Fetching repository name']);
            },
            'Securing site' => function ($results) {
                (new SecureSite)($results['Fetching repository name']);
            },
        ]);

        $this->runSteps();
    }
}
