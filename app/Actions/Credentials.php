<?php

namespace App\Actions;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Single source of truth for the persisted CLI credentials in ~/.rocketeers/.env.
 *
 * Config (config/rocketeers.php) is resolved once at boot, so every writer here
 * ALSO refreshes the in-memory config value — otherwise a same-process
 * write-then-authenticated-call (e.g. `api:auth` saving a token then calling
 * `me`) would keep using the stale value loaded at startup.
 */
class Credentials
{
    use AsAction;

    /**
     * Env key => config key it feeds in config/rocketeers.php.
     */
    private const KEYS = [
        'API_TOKEN' => 'rocketeers.api_token',
        'DEFAULT_TEAM' => 'rocketeers.default_team',
        'API_URL' => 'rocketeers.api_url',
    ];

    public function token(): ?string
    {
        return config('rocketeers.api_token');
    }

    public function defaultTeam(): ?string
    {
        return config('rocketeers.default_team');
    }

    public function apiUrl(): ?string
    {
        return config('rocketeers.api_url');
    }

    /**
     * Persist a key to ~/.rocketeers/.env and refresh the live config value.
     */
    public function store(string $key, string $value): void
    {
        $env = $this->read()->put($key, $value);

        $this->write($env);

        $this->refresh($key, $value);
    }

    /**
     * Remove a key from ~/.rocketeers/.env and null the live config value.
     */
    public function forget(string $key): void
    {
        $env = $this->read()->forget($key);

        $this->write($env);

        $this->refresh($key, null);
    }

    private function read(): \Illuminate\Support\Collection
    {
        if (! Storage::exists('.env')) {
            return collect();
        }

        return collect(Dotenv::parse(Storage::get('.env')));
    }

    private function write(\Illuminate\Support\Collection $env): void
    {
        $contents = $env
            ->sortKeys()
            ->map(function ($value, $key) {
                if (preg_match('/\s|=/', (string) $value)) {
                    $value = '"'.$value.'"';
                }

                return $key.'='.$value;
            })
            ->implode(PHP_EOL);

        Storage::put('.env', $contents);
    }

    private function refresh(string $key, ?string $value): void
    {
        if (isset(self::KEYS[$key])) {
            config([self::KEYS[$key] => $value]);
        }
    }
}
