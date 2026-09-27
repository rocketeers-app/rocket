<?php

namespace App\Commands;

use App\Actions\FetchCurrentUser;
use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

class Me extends Command
{
    use OutputsJson;
    use WithSteps;

    protected $signature = 'me';

    protected $description = 'Show the Rocketeers account your API token belongs to';

    public function handle(): int
    {

        $user = $this->step('Fetching your account', fn () => (new FetchCurrentUser)());

        if ($this->wantsJson()) {
            return $this->emitJson($user);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Name', trim(($user['name'] ?? '') ?: ($user['firstname'] ?? '').' '.($user['lastname'] ?? '')));
        $this->components->twoColumnDetail('Email', $user['email']);

        if (filled(config('rocketeers.default_team'))) {
            $this->components->twoColumnDetail('Team', (string) config('rocketeers.default_team'));
        }

        return self::SUCCESS;
    }
}
