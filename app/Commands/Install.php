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
use Illuminate\Console\Command;

use function Laravel\Prompts\spin;

class Install extends Command
{
    protected $signature = 'install {site} {--server=} {--php=8.0}';

    protected $description = 'Install site';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;
        $phpVersion = $this->option('php');

        $url = spin(
            fn () => (new GetRepositoryUrl)($site, $server),
            'Fetching repository URL...'
        );

        $name = spin(
            fn () => (new GetRepositoryName)($site, $server),
            'Fetching repository name...'
        );

        $branch = spin(
            fn () => (new GetCurrentBranch)($site, $server),
            'Fetching current branch...'
        );

        (new ChangeWorkingDirectory)($name);

        spin(
            fn () => (new GitCloneRepository)($name, $url),
            'Cloning repository...'
        );

        spin(
            fn () => (new CheckoutBranchLocally)($name, $branch),
            'Checking out branch...'
        );

        spin(
            fn () => (new IsolatePhpVersion)($name, $phpVersion),
            'Isolating PHP version...'
        );

        $env = spin(
            fn () => (new GetRemoteDotEnv)($site, $server),
            'Fetching remote .env...'
        );

        $env = (new ConfigureDotEnvLocally)($env, $name);

        (new PutEnvLocally)($env, $name);

        $importAction = new ImportRemoteDatabase;

        $credentials = spin(
            fn () => $importAction->fetchCredentials($site, $server),
            'Fetching database credentials...'
        );

        spin(
            fn () => $importAction->prepareLocalDatabase($credentials['name']),
            'Preparing local database...'
        );

        spin(
            fn () => $importAction->importDatabase($credentials, $server),
            'Importing remote database...'
        );

        spin(
            fn () => (new ComposerInstall)($name),
            'Running composer install...'
        );

        spin(
            fn () => (new RunMigrations)($name),
            'Running migrations...'
        );

        spin(
            fn () => (new NpmInstall)($name),
            'Running npm install...'
        );

        spin(
            fn () => (new SecureSite)($name),
            'Securing site...'
        );
    }
}
