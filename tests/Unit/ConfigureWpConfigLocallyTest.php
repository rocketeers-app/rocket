<?php

use App\Actions\ConfigureWpConfigLocally;

it('rewrites wp-config.php defines for local development', function () {
    $config = <<<'PHP'
    <?php
    define('DB_NAME', 'production_db');
    define('DB_USER', 'produser');
    define('DB_PASSWORD', 'super-secret');
    define('DB_HOST', 'db.internal');
    define('WP_DEBUG', false);
    PHP;

    $result = (new ConfigureWpConfigLocally)($config, 'my-site');

    expect($result)
        ->toContain("define('DB_NAME', 'my-site')")
        ->toContain("define('DB_USER', 'root')")
        ->toContain("define('DB_PASSWORD', '')")
        ->toContain("define('DB_HOST', '127.0.0.1')")
        ->toContain("define('WP_DEBUG', true)")
        ->not->toContain('super-secret')
        ->not->toContain('production_db')
        ->not->toContain('db.internal');
});

it('leaves unrelated defines untouched', function () {
    $config = "<?php\ndefine('WP_CACHE', true);";

    $result = (new ConfigureWpConfigLocally)($config, 'my-site');

    expect($result)->toContain("define('WP_CACHE', true);");
});
