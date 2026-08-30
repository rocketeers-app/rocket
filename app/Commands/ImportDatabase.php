<?php

namespace App\Commands;

use App\Actions\ImportRemoteDatabase;
use App\Actions\NotifyLocally;
use App\Commands\Concerns\ValidatesSiteArguments;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

class ImportDatabase extends Command
{
    use ValidatesSiteArguments, WithSteps;

    protected $signature = 'db:import {site} {--server=} {--user=rocketeer}';

    protected $description = 'Import database';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;

        if (! $this->validateSiteAndServer($site, $server)) {
            return self::FAILURE;
        }

        $action = new ImportRemoteDatabase;

        $this->startProgress(3);

        $credentials = $this->step('Fetching remote credentials', fn () => $action->fetchCredentials($site, $server));
        $this->step('Preparing local database', fn () => $action->prepareLocalDatabase($credentials['name']));
        $this->step('Importing remote database', fn () => $action->importDatabase($credentials, $server));

        $this->finishProgress();

        (new NotifyLocally)("Database is imported for {$site}", $this);
    }
}
