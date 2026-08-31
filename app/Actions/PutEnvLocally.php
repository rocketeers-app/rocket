<?php

namespace App\Actions;

use App\Support\LocalSitePath;
use Lorisleiva\Actions\Concerns\AsAction;

class PutEnvLocally
{
    use AsAction;

    public function handle($env, $name)
    {
        file_put_contents(LocalSitePath::for($name).'/.env', $env);
    }
}
