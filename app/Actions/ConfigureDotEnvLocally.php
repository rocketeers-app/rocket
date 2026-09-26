<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

class ConfigureDotEnvLocally
{
    use AsAction;

    public function handle($env, $name, ?string $engine = null)
    {
        $engine ??= $this->engine($env);

        $env = preg_replace('/^APP_DEBUG=(.*)/m', 'APP_DEBUG=true', $env);
        $env = preg_replace('/^APP_ENV=(.*)/m', 'APP_ENV=local', $env);
        $env = preg_replace('/^APP_URL=(.*)/m', 'APP_URL=https://'.$name.'.test', $env);
        $env = preg_replace('/^CACHE_DRIVER=(.*)/m', 'CACHE_DRIVER=array', $env);
        $env = preg_replace('/^DB_DATABASE=(.*)/m', 'DB_DATABASE='.$name, $env);
        $env = preg_replace('/^DB_HOST=(.*)/m', 'DB_HOST=127.0.0.1', $env);
        $env = preg_replace('/^DB_PASSWORD=(.*)/m', 'DB_PASSWORD=', $env);
        $env = preg_replace('/^DB_SOCKET=(.*)/m', 'DB_SOCKET=', $env);
        $env = preg_replace('/^DB_PORT=(.*)/m', 'DB_PORT='.($engine === 'pgsql' ? '5432' : '3306'), $env);
        $env = preg_replace('/^DB_USERNAME=(.+)/m', 'DB_USERNAME='.($engine === 'pgsql' ? config('rocketeers.local_pgsql_user') : 'root'), $env);
        $env = preg_replace('/^SESSION_DOMAIN=(.+)/m', '', $env);

        return $env;
    }

    private function engine(string $env): string
    {
        return preg_match('/^DB_CONNECTION=["\']?pgsql/m', $env) === 1 ? 'pgsql' : 'mysql';
    }
}
