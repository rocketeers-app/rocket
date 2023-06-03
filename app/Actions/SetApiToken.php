<?php

namespace App\Actions;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsAction;

class SetApiToken
{
    use AsAction;

    public function handle($console)
    {
        if (blank(config('rocketeers.api_token'))) {
            $apiKey = $console->ask('Please provide your Rocketeers app API key');

            Storage::makeDirectory('~/.rocketeers');

            if (! Storage::exists('.env')) {
                Storage::put('.env', '');
            }

            $env = collect(Dotenv::parse(Storage::get('.env')))
                ->put('API_TOKEN', $apiKey)
                ->sortKeys()
                ->map(function ($value, $key) {
                    if (preg_match('/\s|\=/', $value)) {
                        $value = '"'.$value.'"';
                    }

                    return $key.'='.$value;
                })
                ->implode(PHP_EOL);

            Storage::put('.env', $env);
        }
    }
}
