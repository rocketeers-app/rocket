<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

class PutEnvLocally
{
    use AsAction;

    public function handle($env, $name)
    {
        file_put_contents("/var/www/{$name}/.env", $env);
    }
}
