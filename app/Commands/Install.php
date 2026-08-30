<?php

namespace App\Commands;

use App\Actions\ChangeWorkingDirectory;
use App\Actions\CheckoutBranchLocally;
use App\Actions\ComposerInstall;
use App\Actions\ConfigureDotEnvLocally;
use App\Actions\DetectRemotePhpVersion;
use App\Actions\DetectRemoteSite;
use App\Actions\GetRemoteDotEnv;
use App\Actions\GitCloneRepository;
use App\Actions\ImportRemoteDatabase;
use App\Actions\IsolatePhpVersion;
use App\Actions\NpmInstall;
use App\Actions\PutEnvLocally;
use App\Actions\RunMigrations;
use App\Actions\SecureSite;
use App\Commands\Concerns\ValidatesSiteArguments;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

class Install extends Command
{
    use ValidatesSiteArguments, WithSteps;

    protected $signature = 'install {site} {--server=} {--php=}';

    protected $description = 'Install site';

    public function handle()
    {
        return $this->runWithSteps(function () {
            $site = $this->argument('site');
            $server = $this->option('server') ?? $site;
            $phpVersion = $this->option('php');

            if (! $this->validateSiteAndServer($site, $server)) {
                return self::FAILURE;
            }

            $remoteSite = (new DetectRemoteSite)($site, $server);

            if ($remoteSite->isWordPress) {
                return $this->call('sync', [
                    'site' => $site,
                    '--server' => $server,
                ]);
            }

            if ($remoteSite->repositoryUrl === '') {
                $this->error("Could not fetch repository URL for {$site}.");

                return self::FAILURE;
            }

            if ($remoteSite->branch === '') {
                $this->error("Could not fetch current branch for {$site}.");

                return self::FAILURE;
            }

            $name = $remoteSite->repositoryName;
            $url = $remoteSite->repositoryUrl;
            $branch = $remoteSite->branch;

            $this->startProgress($phpVersion ? 11 : 12);

            if (! $phpVersion) {
                $phpVersion = $this->step('Detecting remote PHP version', fn () => (new DetectRemotePhpVersion)($site, $server));
            }

            (new ChangeWorkingDirectory)($name);

            $this->step('Cloning repository', fn () => (new GitCloneRepository)($name, $url));
            $this->step('Checking out branch', fn () => (new CheckoutBranchLocally)($name, $branch));
            $this->step('Isolating PHP version', fn () => (new IsolatePhpVersion)($name, $phpVersion));

            $env = $this->step('Fetching remote .env', fn () => (new GetRemoteDotEnv)($site, $server));
            $env = (new ConfigureDotEnvLocally)($env, $name);
            (new PutEnvLocally)($env, $name);

            $importAction = new ImportRemoteDatabase;
            $credentials = $this->step('Fetching database credentials', fn () => $importAction->fetchCredentials($site, $server, $remoteSite));
            $this->step('Preparing local database', fn () => $importAction->prepareLocalDatabase($credentials['name']));
            $this->step('Importing remote database', fn () => $importAction->importDatabase($credentials, $server));

            $this->step('Running composer install', fn () => (new ComposerInstall)($name));
            $this->step('Running migrations', fn () => (new RunMigrations)($name));
            $this->step('Running npm install', fn () => (new NpmInstall)($name));
            $this->step('Securing site', fn () => (new SecureSite)($name));

            $this->finishProgress();

            $this->line('');
            $this->info("View in browser: https://{$name}.test");
        });
    }
}
