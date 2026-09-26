<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

class PutWpConfigLocally
{
    use AsAction;

    public function handle($config, $name, ?string $directory = null): string
    {
        $directory ??= "/var/www/{$name}";

        $paths = [
            "{$directory}/wp-config.php",
            "{$directory}/public/wp-config.php",
            "{$directory}/config/application.php",
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                file_put_contents($path, $config);

                return $path;
            }
        }

        file_put_contents("{$directory}/wp-config.php", $config);

        return "{$directory}/wp-config.php";
    }
}
