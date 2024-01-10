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
use App\Actions\NotifyLocally;
use App\Actions\NpmInstall;
use App\Actions\PutEnvLocally;
use App\Actions\RunMigrations;
use App\Actions\SecureSite;
use Illuminate\Console\Command;

class Install extends Command
{
    protected $signature = 'install {site} {--server=} {--php=8.0}';

    protected $description = 'Install site';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;
        $phpVersion = $this->option('php');

        (new ChangeWorkingDirectory)($name);

        $url = (new GetRepositoryUrl)($site, $server);
        $name = (new GetRepositoryName)($site, $server);
        $branch = (new GetCurrentBranch)($site, $server);

        (new GitCloneRepository)($name, $url);

        (new CheckoutBranchLocally)($name, $branch);

        (new IsolatePhpVersion)($name, $phpVersion);

        $env = (new GetRemoteDotEnv)($site, $server);
        $env = (new ConfigureDotEnvLocally)($env, $name);

        (new PutEnvLocally)($env, $name);

        (new ImportRemoteDatabase)($site, $server);

        (new ComposerInstall)($name);
        (new RunMigrations)($name);
        (new NpmInstall)($name);
        (new SecureSite)($name);

        // (new NotifyLocally)($message);
    }
}
