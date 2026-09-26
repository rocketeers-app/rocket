<?php

namespace App\Commands;

use App\Actions\FetchCurrentUser;
use App\Actions\RequestApi;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

class Me extends Command
{
    use WithSteps;

    protected $signature = 'me';

    protected $description = 'Show the Rocketeers account your API token belongs to';

    public function handle(): int
    {
        if (blank(config('rocketeers.api_token'))) {
            $this->error('No Rocketeers token configured. '.RequestApi::SETUP_HINT);

            return self::FAILURE;
        }

        $this->startProgress(1);

        $user = $this->step('Fetching your account', fn () => (new FetchCurrentUser)());

        $this->finishProgress();

        $this->newLine();
        $this->components->twoColumnDetail('Name', $user['name'] ?? '');
        $this->components->twoColumnDetail('Email', $user['email']);

        return self::SUCCESS;
    }
}
