<?php

namespace App\Commands;

use App\Actions\ConfigureDotEnvLocally;
use App\Actions\GetRemoteDotEnv;
use App\Actions\GetRepositoryName;
use App\Actions\PutEnvLocally;
use Illuminate\Console\Command;

class PullEnv extends Command
{
    protected $signature = 'env:pull {site} {--server=}';
    protected $description = 'Tail logs from sites';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;

        $name = (new GetRepositoryName)($site, $server);
        $env = (new GetRemoteDotEnv)($site, $server);
        $env = (new ConfigureDotEnvLocally)($env, $name);

        (new PutEnvLocally)($env, $name);
    }
}
