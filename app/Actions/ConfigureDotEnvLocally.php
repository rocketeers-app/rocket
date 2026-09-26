<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

class ConfigureDotEnvLocally
{
    use AsAction;

    public function handle($env, $name)
    {
        $env = preg_replace('/^APP_DEBUG=(.*)/m', 'APP_DEBUG=true', $env);
        $env = preg_replace('/^APP_ENV=(.*)/m', 'APP_ENV=local', $env);
        $env = preg_replace('/^APP_URL=(.*)/m', 'APP_URL=https://'.$name.'.test', $env);
        $env = preg_replace('/^CACHE_DRIVER=(.*)/m', 'CACHE_DRIVER=array', $env);
        $env = preg_replace('/^DB_DATABASE=(.*)/m', 'DB_DATABASE='.$name, $env);
        $env = preg_replace('/^DB_HOST=(.*)/m', 'DB_HOST=127.0.0.1', $env);
        $env = preg_replace('/^DB_PASSWORD=(.*)/m', 'DB_PASSWORD=', $env);
        $env = preg_replace('/^DB_PORT=(.*)/m', 'DB_PORT='.$name, $env);
        $env = preg_replace('/^DB_USERNAME=(.+)/m', 'DB_USERNAME=root', $env);
        $env = preg_replace('/^SESSION_DOMAIN=(.+)/m', '', $env);

        return $env;
    }
}
