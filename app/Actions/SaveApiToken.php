<?php

namespace App\Actions;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsAction;

class SaveApiToken
{
    use AsAction;

    public function handle(string $token): void
    {
        $current = Storage::exists('.env') ? Dotenv::parse(Storage::get('.env')) : [];

        $contents = collect($current)
            ->put('API_TOKEN', $token)
            ->sortKeys()
            ->map(fn ($value, $key) => $key.'='.(preg_match('/\s|=|#|"/', (string) $value) ? '"'.addcslashes((string) $value, '"\\').'"' : $value))
            ->implode(PHP_EOL);

        Storage::put('.env', $contents.PHP_EOL);

        chmod(Storage::path('.env'), 0600);

        config(['rocketeers.api_token' => $token]);
    }
}
