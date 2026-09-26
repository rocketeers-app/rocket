<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

class PutEnvLocally
{
    use AsAction;

    public function handle($env, $name, ?string $directory = null): string
    {
        $path = ($directory ?? "/var/www/{$name}").'/.env';

        file_put_contents($path, $env);

        return $path;
    }
}
