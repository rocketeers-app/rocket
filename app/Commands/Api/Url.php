<?php

namespace App\Commands\Api;

use App\Actions\Credentials;
use Illuminate\Console\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

class Url extends Command
{
    protected $signature = 'url {url? : Base API URL, e.g. https://rocketeers-app-v2.test/api/v1}';

    protected $description = 'Set or show the API base URL';

    public function handle(): int
    {
        $credentials = Credentials::make();

        if ($url = $this->argument('url')) {
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                error('URL must start with http:// or https://');

                return self::FAILURE;
            }

            $url = rtrim($url, '/');
            $credentials->store('API_URL', $url);
            info("API URL set to: {$url}");

            return self::SUCCESS;
        }

        note('Current API URL: '.$credentials->apiUrl());

        return self::SUCCESS;
    }
}
