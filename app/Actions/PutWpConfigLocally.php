<?php

namespace App\Actions;

use App\Support\LocalSitePath;
use Lorisleiva\Actions\Concerns\AsAction;

class PutWpConfigLocally
{
    use AsAction;

    public function handle($config, $name)
    {
        $base = LocalSitePath::for($name);

        $paths = [
            "{$base}/wp-config.php",
            "{$base}/public/wp-config.php",
            "{$base}/config/application.php",
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                file_put_contents($path, $config);

                return;
            }
        }

        file_put_contents("{$base}/wp-config.php", $config);
    }
}
