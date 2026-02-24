<?php

namespace App\Commands;

use App\Actions\ImportRemoteDatabase;
use App\Actions\NotifyLocally;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

class ImportDatabase extends Command
{
    use WithSteps;

    protected $signature = 'db:import {site} {--server=} {--user=rocketeer}';

    protected $description = 'Import database';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;

        $action = new ImportRemoteDatabase;

        $this->registerSteps([
            'Fetching remote credentials' => function () use ($action, $site, $server) {
                return $action->fetchCredentials($site, $server);
            },
            'Preparing local database' => function ($results) use ($action) {
                $action->prepareLocalDatabase($results['Fetching remote credentials']['name']);
            },
            'Importing remote database' => function ($results) use ($action, $server) {
                $action->importDatabase($results['Fetching remote credentials'], $server);
            },
        ]);

        $this->runSteps();

        (new NotifyLocally)("Database is imported for {$site}", $this);
    }
}
