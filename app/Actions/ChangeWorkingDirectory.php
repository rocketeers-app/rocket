<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

class ChangeWorkingDirectory
{
    use AsAction;

    public function handle($name)
    {
        chdir("/var/www/{$name}");
    }
}
