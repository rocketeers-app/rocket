<?php

namespace App\Commands;

use App\Commands\Concerns\OutputsJson;
use App\Support\Teams as TeamRepository;
use Illuminate\Console\Command;

class Teams extends Command
{
    use OutputsJson;

    protected $signature = 'teams';

    protected $description = 'List the teams your token reaches';

    public function handle(TeamRepository $teams): int
    {
        $all = $teams->all(fresh: true);

        if ($this->wantsJson()) {
            return $this->emitJson(['data' => $all]);
        }

        $current = config('rocketeers.default_team');

        $this->newLine();
        $this->table(['', 'Name', 'Slug', 'Permissions'], array_map(fn (array $team): array => [
            $team['slug'] === $current ? '<fg=green>●</>' : '',
            $team['name'],
            $team['slug'],
            is_array($team['permissions'] ?? null) ? count($team['permissions']) : '—',
        ], $all));

        return self::SUCCESS;
    }
}
