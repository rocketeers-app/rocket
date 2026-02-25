<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

class PutWpConfigLocally
{
    use AsAction;

    public function handle($config, $name)
    {
        $paths = [
            "/var/www/{$name}/wp-config.php",
            "/var/www/{$name}/public/wp-config.php",
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                file_put_contents($path, $config);

                return;
            }
        }

        file_put_contents("/var/www/{$name}/wp-config.php", $config);
    }
}
