<?php

namespace App\Actions;

use Dotenv\Dotenv;
use Lorisleiva\Actions\Concerns\AsAction;

class SetApiToken
{
    use AsAction;

    public function handle($console)
    {
        if (blank(config('rocketeers.api_token'))) {
            $apiKey = $console->ask('Please provide your Rocketeers app API key');

            touch(base_path('.env'));

            $env = collect(Dotenv::parse(file_get_contents(base_path('.env'))))
                ->put('API_TOKEN', $apiKey)
                ->sortKeys()
                ->map(function ($value, $key) {
                    if (preg_match('/\s|\=/', $value)) {
                        $value = '"'.$value.'"';
                    }

                    return $key.'='.$value;
                })
                ->implode(PHP_EOL);

            file_put_contents(base_path('.env'), $env);
        }
    }
}
