<?php

namespace App\Commands;

use App\Actions\ConfigureDotEnvLocally;
use App\Actions\GetRemoteDotEnv;
use App\Actions\GetRepositoryName;
use App\Actions\NotifyLocally;
use App\Actions\PutEnvLocally;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

class EnvPull extends Command
{
    use WithSteps;

    protected $signature = 'env:pull {site} {--server=}';

    protected $description = 'Pull env for site from remote server';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;

        $this->startProgress(3);

        $name = $this->step('Fetching repository name', fn () => (new GetRepositoryName)($site, $server));
        $env = $this->step('Fetching remote .env', fn () => (new GetRemoteDotEnv)($site, $server));
        $env = (new ConfigureDotEnvLocally)($env, $name);

        $this->step('Saving .env locally', fn () => (new PutEnvLocally)($env, $name));

        $this->finishProgress();

        (new NotifyLocally)("Env pulled for {$site}", $this);
    }
}
