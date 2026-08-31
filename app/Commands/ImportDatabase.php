<?php

namespace App\Commands;

use App\Actions\ImportRemoteDatabase;
use App\Actions\NotifyLocally;
use App\Commands\Concerns\ConfirmsDestructiveAction;
use App\Commands\Concerns\ValidatesSiteArguments;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

class ImportDatabase extends Command
{
    use ConfirmsDestructiveAction, ValidatesSiteArguments, WithSteps;

    protected $signature = 'db:import {site} {--server=} {--user=rocketeer} {--force}';

    protected $description = 'Import database';

    public function handle()
    {
        return $this->runWithSteps(function () {
            $site = $this->argument('site');
            $server = $this->option('server') ?? $site;

            if (! $this->validateSiteAndServer($site, $server)) {
                return self::FAILURE;
            }

            $action = new ImportRemoteDatabase;
            $credentials = $action->fetchCredentials($site, $server);

            if (! $this->confirmDestructiveAction("This will drop and recreate the local '{$credentials['name']}' database. Continue?")) {
                $this->warn('Aborted.');

                return self::SUCCESS;
            }

            $this->startProgress(2);

            $this->step('Preparing local database', fn () => $action->prepareLocalDatabase($credentials['name']));
            $this->step('Importing remote database', fn () => $action->importDatabase($credentials, $server));

            $this->finishProgress();

            (new NotifyLocally)("Database is imported for {$site}", $this);
        });
    }
}
