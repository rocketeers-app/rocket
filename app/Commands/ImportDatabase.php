<?php

namespace App\Commands;

use App\Actions\ImportRemoteDatabase;
use App\Actions\NotifyLocally;
use Illuminate\Console\Command;

use function Laravel\Prompts\spin;

class ImportDatabase extends Command
{
    protected $signature = 'db:import {site} {--server=} {--user=rocketeer}';

    protected $description = 'Import database';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;

        $action = new ImportRemoteDatabase;

        $credentials = spin(
            fn () => $action->fetchCredentials($site, $server),
            'Fetching remote credentials...'
        );

        spin(
            fn () => $action->prepareLocalDatabase($credentials['name']),
            'Preparing local database...'
        );

        spin(
            fn () => $action->importDatabase($credentials, $server),
            'Importing remote database...'
        );

        (new NotifyLocally)("Database is imported for {$site}", $this);
    }
}
