<?php

namespace App\Providers;

use App\Actions\RefreshSchema;
use App\Console\OperationCommand;
use App\Console\ResourceCommand;
use App\Schema\SchemaCache;
use App\Support\PermissionGate;
use App\Support\Teams;
use Illuminate\Console\Application as Artisan;
use Illuminate\Support\ServiceProvider;
use Throwable;

/** Registers one command per team API operation and one overview per resource, from the cached schema, marking what the current team does not allow. */
class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Artisan::starting(function (Artisan $artisan): void {
            $this->refreshStaleSchema();

            $schema = $this->app->make(SchemaCache::class);

            foreach ($schema->operations() as $operation) {
                if (! $artisan->has($operation->command)) {
                    $artisan->add(new OperationCommand($operation));
                }
            }

            foreach ($schema->groupedByItem() as $items) {
                foreach ($items as $item => $operations) {
                    if (! $artisan->has($item)) {
                        $artisan->add(new ResourceCommand($item, $operations));
                    }
                }
            }
        });
    }

    public function register(): void
    {
        $this->app->singleton(SchemaCache::class);
        $this->app->singleton(Teams::class);
        $this->app->singleton(PermissionGate::class);
    }

    private function refreshStaleSchema(): void
    {
        if (! config('rocketeers.auto_refresh') || $this->app->runningUnitTests() || blank(config('rocketeers.api_token'))) {
            return;
        }

        if (! $this->app->make(SchemaCache::class)->isStale()) {
            return;
        }

        try {
            (new RefreshSchema)();
        } catch (Throwable) {
            $this->app->make(SchemaCache::class)->touch();
        }
    }
}
