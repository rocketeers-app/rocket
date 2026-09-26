<?php

namespace App\Actions;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsAction;

class SaveSettings
{
    use AsAction;

    private const array CONFIG_KEYS = [
        'API_TOKEN' => 'rocketeers.api_token',
        'API_URL' => 'rocketeers.api_url',
        'DEFAULT_TEAM' => 'rocketeers.default_team',
    ];

    /** @param array<string, string|null> $values */
    public function handle(array $values): void
    {
        $current = Storage::exists('.env') ? Dotenv::parse(Storage::get('.env')) : [];

        $contents = collect($current)
            ->merge($values)
            ->reject(fn (?string $value): bool => $value === null)
            ->sortKeys()
            ->map(fn (string $value, string $key): string => $key.'='.(preg_match('/\s|=|#|"/', $value) ? '"'.addcslashes($value, '"\\').'"' : $value))
            ->implode(PHP_EOL);

        Storage::put('.env', $contents.PHP_EOL);

        chmod(Storage::path('.env'), 0600);

        foreach ($values as $key => $value) {
            if (isset(self::CONFIG_KEYS[$key])) {
                config([self::CONFIG_KEYS[$key] => $value]);
            }
        }
    }
}
