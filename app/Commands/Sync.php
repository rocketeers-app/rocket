<?php

namespace App\Commands;

use App\Actions\ConfigureDotEnvLocally;
use App\Actions\ConfigureWpConfigLocally;
use App\Actions\DetectRemoteSite;
use App\Actions\GetRemoteDotEnv;
use App\Actions\GetRemoteWpConfig;
use App\Actions\ImportRemoteDatabase;
use App\Actions\NotifyLocally;
use App\Actions\PutEnvLocally;
use App\Actions\PutWpConfigLocally;
use App\Actions\RsyncSite;
use App\Actions\SecureSite;
use App\Commands\Concerns\ConfirmsDestructiveAction;
use App\Commands\Concerns\ValidatesSiteArguments;
use App\Commands\Concerns\WithSteps;
use App\Support\LocalSitePath;
use Illuminate\Console\Command;

class Sync extends Command
{
    use ConfirmsDestructiveAction, ValidatesSiteArguments, WithSteps;

    protected $signature = 'sync {site} {--server=} {--force} {--dry-run}';

    protected $description = 'Sync site';

    public function handle()
    {
        return $this->runWithSteps(function () {
            $site = $this->argument('site');
            $server = $this->option('server') ?? $site;
            $dryRun = (bool) $this->option('dry-run');

            if (! $this->validateSiteAndServer($site, $server)) {
                return self::FAILURE;
            }

            $remoteSite = (new DetectRemoteSite)($site, $server);
            $name = $remoteSite->repositoryName;

            if ($dryRun) {
                $this->info("Dry run for {$name} - showing what rsync would change locally. Nothing is written, and the database is not touched.");
                $this->newLine();
                $this->line((new RsyncSite)($name, $site, $server, dryRun: true));

                return self::SUCCESS;
            }

            if (! $this->confirmDestructiveAction(
                'This will overwrite local files in '.LocalSitePath::for($name)." (rsync --delete) and drop and recreate the local '{$name}' database. Continue?"
            )) {
                $this->warn('Aborted.');

                return self::SUCCESS;
            }

            $this->startProgress(7);

            $this->step('Syncing files from remote', fn () => (new RsyncSite)($name, $site, $server));

            if ($remoteSite->isWordPress && ! $remoteSite->isBedrock) {
                $config = $this->step('Fetching remote wp-config.php', fn () => (new GetRemoteWpConfig)($site, $server));
                $config = (new ConfigureWpConfigLocally)($config, $name);
                $this->step('Saving wp-config.php locally', fn () => (new PutWpConfigLocally)($config, $name));
            } else {
                $env = $this->step('Fetching remote .env', fn () => (new GetRemoteDotEnv)($site, $server));
                $env = (new ConfigureDotEnvLocally)($env, $name);
                $this->step('Saving .env locally', fn () => (new PutEnvLocally)($env, $name));
            }

            $importAction = new ImportRemoteDatabase;
            $credentials = $this->step('Fetching database credentials', fn () => $importAction->fetchCredentials($site, $server, $remoteSite));
            $this->step('Preparing local database', fn () => $importAction->prepareLocalDatabase($credentials['name']));
            $this->step('Importing remote database', fn () => $importAction->importDatabase($credentials, $server));

            $this->step('Securing site', fn () => (new SecureSite)($name));

            $this->finishProgress();

            (new NotifyLocally)("Site {$site} is now in sync.", $this);

            $this->line('');
            $this->info("View in browser: https://{$name}.test");
        });
    }
}
