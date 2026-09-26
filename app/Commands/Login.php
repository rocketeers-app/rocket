<?php

namespace App\Commands;

use App\Actions\FetchCurrentUser;
use App\Actions\SaveApiToken;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

class Login extends Command
{
    use WithSteps;

    protected $signature = 'login';

    protected $description = 'Configure your Rocketeers API token';

    public function handle(): int
    {
        $token = trim(password(
            label: 'Your Rocketeers API token',
            required: true,
            hint: 'Create one in Rocketeers under Settings, API.',
        ));

        $this->startProgress(2);

        $user = $this->step('Verifying API token', fn () => (new FetchCurrentUser)($token));

        $this->step('Saving API token', fn () => (new SaveApiToken)($token));

        $this->finishProgress();

        $this->newLine();
        $this->info("Logged in as {$user['name']} ({$user['email']}).");

        return self::SUCCESS;
    }
}
