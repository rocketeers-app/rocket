<?php

namespace App\Commands;

use App\Actions\ComposerInstall;
use App\Actions\ConfigureDotEnvLocally;
use App\Actions\GetRemoteDotEnv;
use App\Actions\GetRepositoryName;
use App\Actions\GitPullRepository;
use App\Actions\ImportRemoteDatabase;
use App\Actions\NotifyLocally;
use App\Actions\NpmInstall;
use App\Actions\PutEnvLocally;
use App\Actions\RunMigrations;
use Illuminate\Console\Command;

class Sync extends Command
{
    protected $signature = 'sync {site} {--server=}';
    protected $description = 'Sync site';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;

        $name = (new GetRepositoryName)($site, $server);

        (new GitPullRepository)();
        $env = (new GetRemoteDotEnv)($site, $server);
        $env = (new ConfigureDotEnvLocally)($env, $name);

        (new PutEnvLocally)($env, $name);

        (new NotifyLocally)("Env pulled for {$site}", $this);

        (new ImportRemoteDatabase)($site, $server);

        (new ComposerInstall)($name);
        (new RunMigrations)($name);
        (new NpmInstall)($name);

        (new NotifyLocally)("Site {$site} is now in sync.", $this);
    }
}
