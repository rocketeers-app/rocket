<?php

namespace App\Commands;

use App\Actions\FetchCurrentUser;
use App\Actions\SaveApiToken;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

class SetupToken extends Command
{
    use WithSteps;

    protected $signature = 'setup-token {token : The Rocket CLI token from Rocketeers, Settings, API}';

    protected $description = 'Authenticate Rocket with a Rocket CLI token';

    public function handle(): int
    {
        $token = trim((string) $this->argument('token'));

        $this->startProgress(2);

        $user = $this->step('Verifying token', fn () => (new FetchCurrentUser)($token));

        $this->step('Saving token', fn () => (new SaveApiToken)($token));

        $this->finishProgress();

        $this->newLine();
        $this->info("Authenticated as {$user['name']} ({$user['email']}).");

        return self::SUCCESS;
    }
}
