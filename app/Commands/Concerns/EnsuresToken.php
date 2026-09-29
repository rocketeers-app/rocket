<?php

namespace App\Commands\Concerns;

use App\Actions\FetchCurrentUser;
use App\Actions\RefreshSchema;
use App\Actions\SaveApiToken;
use App\Api\ApiErrorPresenter;
use App\Exceptions\StepException;
use App\Support\Teams;

use function Laravel\Prompts\password;

/** A token before anything talks to the API: the saved one, else — when someone is there to answer — one asked for, verified and saved. */
trait EnsuresToken
{
    use WithSteps;

    protected function ensureToken(): void
    {
        if (filled(config('rocketeers.api_token'))) {
            return;
        }

        if (! $this->canPrompt()) {
            throw ApiErrorPresenter::missingToken();
        }

        $this->authenticate($this->askForToken());
    }

    protected function askForToken(): string
    {
        return trim(password(label: 'Your Rocket CLI token', required: true, hint: 'Rocketeers → Settings → API'));
    }

    protected function authenticate(string $token): array
    {
        $user = $this->step('Verifying token', fn () => (new FetchCurrentUser)($token));

        $this->step('Saving token', fn () => (new SaveApiToken)($token));

        $teams = $this->step('Fetching your teams', fn () => app(Teams::class)->all(fresh: true));

        $this->step('Fetching the API schema', function (): void {
            try {
                (new RefreshSchema)();
            } catch (StepException) {
            }
        });

        return ['user' => $user, 'teams' => $teams];
    }
}
