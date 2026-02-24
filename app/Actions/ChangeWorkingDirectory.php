<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

class ChangeWorkingDirectory
{
    use AsAction;

    public function handle($name)
    {
        $path = "/var/www/{$name}";

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        chdir($path);
    }
}
