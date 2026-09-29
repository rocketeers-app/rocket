<?php

namespace App\Commands;

use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\ResolvesTeam;
use App\Commands\Concerns\WithSteps;
use App\Exceptions\ApiException;
use Illuminate\Console\Command;

class SetupToken extends Command
{
    use OutputsJson;
    use ResolvesTeam;
    use WithSteps;

    protected $signature = 'setup-token {token? : The Rocket CLI token from Rocketeers, Settings, API; asked for when left out}';

    protected $description = 'Authenticate Rocket with a Rocket CLI token';

    public function handle(): int
    {
        $token = trim((string) $this->argument('token'));

        if ($token === '') {
            $token = $this->canPrompt()
                ? $this->askForToken()
                : throw new ApiException('Pass the token: rocket setup-token <token>.', 422, errors: ['token' => ['Pass the token as an argument: rocket setup-token <token>.']]);
        }

        ['user' => $user, 'teams' => $all] = $this->authenticate($token);

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
