<?php

use App\Actions\ConfigureDotEnvLocally;

it('points the app at the local domain and local defaults', function () {
    $env = implode("\n", [
        'APP_DEBUG=false',
        'APP_ENV=production',
        'APP_URL=https://example.com',
        'CACHE_DRIVER=redis',
        'DB_DATABASE=production_db',
        'DB_HOST=10.0.0.5',
        'DB_PASSWORD=super-secret',
        'DB_PORT=production_db',
        'DB_USERNAME=produser',
        'SESSION_DOMAIN=.example.com',
    ]);

    $result = (new ConfigureDotEnvLocally)($env, 'my-site');

    expect($result)
        ->toContain('APP_DEBUG=true')
        ->toContain('APP_ENV=local')
        ->toContain('APP_URL=https://my-site.test')
        ->toContain('CACHE_DRIVER=array')
        ->toContain('DB_DATABASE=my-site')
        ->toContain('DB_HOST=127.0.0.1')
        ->toContain('DB_PASSWORD=')
        ->toContain('DB_PORT=my-site')
        ->toContain('DB_USERNAME=root')
        ->not->toContain('super-secret')
        ->not->toContain('production_db')
        ->not->toContain('.example.com');
});

it('leaves unrelated lines untouched', function () {
    $env = "MAIL_MAILER=smtp\nAPP_DEBUG=false";

    $result = (new ConfigureDotEnvLocally)($env, 'my-site');

    expect($result)->toContain('MAIL_MAILER=smtp');
});
