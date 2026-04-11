<?php

namespace App\Commands\Api;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class Auth extends Command
{
    protected $signature = 'api:auth';

    protected $description = 'Configure your Rocketeers API token';

    public function handle(): int
    {
        $apiKey = $this->ask('Enter your Rocketeers API token');

        if (blank($apiKey)) {
            $this->error('No token provided.');

            return self::FAILURE;
        }

        if (! Storage::exists('.env')) {
            Storage::put('.env', '');
        }

        $envContent = Storage::get('.env');
        $lines = collect(explode(PHP_EOL, $envContent))
            ->filter(fn ($line) => ! str_starts_with($line, 'API_TOKEN=') && $line !== '');

        $lines->push("API_TOKEN={$apiKey}");

        Storage::put('.env', $lines->implode(PHP_EOL));

        $this->info('API token saved to ~/.rocketeers/.env');

        return self::SUCCESS;
    }
}
