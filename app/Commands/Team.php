<?php

namespace App\Commands;

use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\ResolvesTeam;
use App\Exceptions\StepException;
use App\Support\Teams;
use Illuminate\Console\Command;

class Team extends Command
{
    use OutputsJson;
    use ResolvesTeam;

    protected $signature = 'team {team? : The slug or name of the team to switch to}';

    protected $description = 'Show the team Rocket works in, or switch to another';

    public function handle(Teams $teams): int
    {
        $identifier = $this->argument('team');

        if (filled($identifier)) {
            $team = $teams->find((string) $identifier, fresh: true)
                ?? throw new StepException("Team `{$identifier}` is not one of your teams. Run `rocket teams` to see them.");
            $teams->select($team);
        } elseif ($this->canPrompt()) {
            $team = $this->chooseTeam(fresh: true);
        } else {
            $teams->all(fresh: true);
            $team = $teams->current() ?? throw new StepException('No team selected. Pass one: rocket team <slug>.');
        }

        if ($this->wantsJson()) {
            return $this->emitJson([
                'team' => collect($team)->except('permissions')->all(),
                'permissions' => $team['permissions'] ?? null,
            ]);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Team', "{$team['name']} <fg=gray>({$team['slug']})</>");

        if (is_array($team['permissions'] ?? null)) {
            $this->components->twoColumnDetail('Permissions', (string) count($team['permissions']));
        }

        $this->newLine();
        $this->line('  Commands now run in this team. Try <fg=cyan>rocket environments:list</>, or just <fg=cyan>rocket</>.');

        return self::SUCCESS;
    }
}
