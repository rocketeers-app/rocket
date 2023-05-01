<?php

namespace App\Actions;

use Dotenv\Dotenv;
use Lorisleiva\Actions\Concerns\AsAction;

class SetApiToken
{
    use AsAction;

    public function handle($console)
    {
        if (! trim((string) env('API_TOKEN'))) {
            $apiKey = $console->ask('Please provide your Rocketeers app API key');

            $env = collect(Dotenv::parse(file_get_contents('.env')))
                ->put('API_TOKEN', $apiKey)
                ->sortKeys()
                ->map(function ($value, $key) {
                    if (preg_match('/\s|\=/', $value)) {
                        $value = '"'.$value.'"';
                    }

                    return $key.'='.$value;
                })
                ->implode(PHP_EOL);

            file_put_contents('.env', $env);
        }
    }
}
