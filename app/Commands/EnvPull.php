<?php

namespace App\Commands;

use App\Actions\ConfigureDotEnvLocally;
use App\Actions\GetRemoteDotEnv;
use App\Actions\GetRepositoryName;
use App\Actions\NotifyLocally;
use App\Actions\PutEnvLocally;
use Illuminate\Console\Command;

class EnvPull extends Command
{
    protected $signature = 'env:pull {site} {--server=}';
    protected $description = 'Pull env for site from remote server';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;

        $name = (new GetRepositoryName)($site, $server);
        $env = (new GetRemoteDotEnv)($site, $server);
        $env = (new ConfigureDotEnvLocally)($env, $name);

        (new PutEnvLocally)($env, $name);

        (new NotifyLocally)("Env pulled for {$site}", $this);
    }
}
