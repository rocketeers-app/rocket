<?php

namespace App\Actions;

use App\Support\LocalSitePath;
use Lorisleiva\Actions\Concerns\AsAction;

class ChangeWorkingDirectory
{
    use AsAction;

    public function handle($name)
    {
        $path = LocalSitePath::for($name);

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        chdir($path);
    }
}
