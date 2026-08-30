<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\LocalSitePath;
use Illuminate\Filesystem\Filesystem;
use Lorisleiva\Actions\Concerns\AsAction;

class RemoveLocalSiteFiles
{
    use AsAction;

    public function handle(string $name): void
    {
        $path = LocalSitePath::for($name);

        if (! is_dir($path)) {
            return;
        }

        if (! (new Filesystem)->deleteDirectory($path)) {
            throw new StepException("Could not remove {$path}.");
        }
    }
}
