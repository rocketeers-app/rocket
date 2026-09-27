<?php

namespace App\Commands;

use App\Actions\FetchCurrentUser;
use App\Actions\RefreshSchema;
use App\Actions\SaveApiToken;
use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\ResolvesTeam;
use App\Commands\Concerns\WithSteps;
use App\Exceptions\StepException;
use App\Support\Teams;
use Illuminate\Console\Command;

class SetupToken extends Command
{
    use OutputsJson;
    use ResolvesTeam;
    use WithSteps;

    protected $signature = 'setup-token {token : The Rocket CLI token from Rocketeers, Settings, API}';

    protected $description = 'Authenticate Rocket with a Rocket CLI token';

    public function handle(Teams $teams): int
    {
        $token = trim((string) $this->argument('token'));

        $user = $this->step('Verifying token', fn () => (new FetchCurrentUser)($token));

        $this->step('Saving token', fn () => (new SaveApiToken)($token));

        $all = $this->step('Fetching your teams', fn () => $teams->all(fresh: true));

        $this->step('Fetching the API schema', function (): void {
            try {
                (new RefreshSchema)();
            } catch (StepException) {
            }
        });

        $current = collect($all)->firstWhere('slug', config('rocketeers.default_team'));
        $team = $current ?? (count($all) === 1 || $this->canPrompt() ? $this->chooseTeam() : null);

        if ($this->wantsJson()) {
            return $this->emitJson(['user' => $user, 'team' => $team === null ? null : collect($team)->except('permissions')->all()]);
        }

        $this->newLine();
        $this->info('Authenticated as '.trim(($user['name'] ?? '') ?: ($user['firstname'] ?? '').' '.($user['lastname'] ?? ''))." ({$user['email']}).");

        if ($team === null) {
            $this->line('  Pick the team to work in with <fg=cyan>rocket team</>.');
        } else {
            $this->line("  Working in team <fg=cyan>{$team['name']}</>. Switch with <fg=cyan>rocket team</>, or start with <fg=cyan>rocket</>.");
        }

        return self::SUCCESS;
    }
}
