<?php

namespace App\Commands;

use App\Actions\DropLocalDatabase;
use App\Actions\RemoveLocalSiteFiles;
use App\Actions\UnsecureSite;
use App\Commands\Concerns\ConfirmsDestructiveAction;
use App\Commands\Concerns\ValidatesSiteArguments;
use App\Commands\Concerns\WithSteps;
use App\Support\LocalSitePath;
use Illuminate\Console\Command;

class RemoveSite extends Command
{
    use ConfirmsDestructiveAction, ValidatesSiteArguments, WithSteps;

    protected $signature = 'remove {site} {--name=} {--force} {--keep-database}';

    protected $description = "Remove a site's local files and database (the remote server is never touched)";

    public function handle()
    {
        return $this->runWithSteps(function () {
            $site = $this->argument('site');

            if (! $this->validateSiteAndServer($site)) {
                return self::FAILURE;
            }

            // install/sync resolve the local directory name from the
            // remote repository, which needs a working SSH connection.
            // Removal deliberately doesn't - the remote server may already
            // be gone - so it defaults to the site argument and lets
            // --name override it when that differs from the local folder.
            $name = $this->option('name') ?: $site;

            if (! $this->isSafeIdentifier($name)) {
                $this->error("Invalid --name \"{$name}\": only letters, numbers, dots, hyphens and underscores are allowed.");

                return self::FAILURE;
            }

            $path = LocalSitePath::for($name);

            if (! is_dir($path)) {
                $this->warn("Nothing to remove: {$path} does not exist.");

                return self::SUCCESS;
            }

            $keepDatabase = (bool) $this->option('keep-database');

            $question = "This will delete {$path}".($keepDatabase ? '' : " and drop the local '{$name}' database").'. Continue?';

            if (! $this->confirmDestructiveAction($question)) {
                $this->warn('Aborted.');

                return self::SUCCESS;
            }

            $this->startProgress($keepDatabase ? 2 : 3);

            $this->step('Unsecuring site', fn () => (new UnsecureSite)($name));

            if (! $keepDatabase) {
                $this->step('Dropping local database', fn () => (new DropLocalDatabase)($name));
            }

            $this->step('Removing local files', fn () => (new RemoveLocalSiteFiles)($name));

            $this->finishProgress();

            $this->info("Removed {$name}.");
        });
    }
}
