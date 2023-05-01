<?php

namespace App\Commands;

use App\Actions\ImportRemoteDatabase;
use App\Actions\NotifyLocally;
use Illuminate\Console\Command;

class ImportDatabase extends Command
{
    protected $signature = 'db:import {site} {--server=} {--user=rocketeer}';
    protected $description = 'Import database';

    public function handle()
    {
        $site = $this->argument('site');
        $server = $this->option('server') ?? $site;

        (new ImportRemoteDatabase)($site, $server);

        (new NotifyLocally)("Database is imported for {$site}", $this);
    }
}
