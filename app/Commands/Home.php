<?php

namespace App\Commands;

use App\Commands\Concerns\OutputsJson;
use App\Schema\Operation;
use App\Schema\SchemaCache;
use App\Support\PermissionGate;
use App\Support\Teams;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * What `rocket` on its own shows: who and where you are, every API resource with its main actions
 * (and how many the current team locks), the local commands, and where to go next.
 */
class Home extends Command
{
    use OutputsJson;

    private const array LOCAL = ['install', 'sync', 'tail', 'env:pull', 'db:import', 'ssh:config'];

    private const array ACCOUNT = ['setup-token', 'me', 'team', 'teams', 'hub', 'api:refresh'];

    private const array LEADING_ACTIONS = ['list', 'read', 'create', 'update', 'delete'];

    private const int ACTIONS_SHOWN = 6;

    protected $signature = 'home';

    protected $description = 'Everything Rocket can do, at a glance';

    public function handle(SchemaCache $schema, Teams $teams, PermissionGate $gate): int
    {
        $signedIn = filled(config('rocketeers.api_token'));
        $team = $signedIn ? $teams->currentFromCache() : null;
        $grouped = $schema->groupedByItem();

        if ($this->wantsJson()) {
            return $this->emitJson([
                'version' => config('app.version'),
                'signed_in' => $signedIn,
                'team' => $team === null ? null : collect($team)->except('permissions')->all(),
                'resources' => collect($grouped)->map(fn (array $items): array => collect($items)
                    ->map(fn (array $operations): array => array_map(fn (Operation $operation): string => $operation->command, $operations))
                    ->all())
                    ->all(),
                'local' => self::LOCAL,
                'account' => self::ACCOUNT,
            ]);
        }

        $this->header($signedIn, $team, count($schema->operations()));

        foreach ($grouped as $group => $items) {
            $this->section($group);

            foreach ($items as $item => $operations) {
                $this->components->twoColumnDetail("<fg=cyan>{$item}</>", $this->actions($item, $operations, $gate, $team));
            }
        }

        $this->section('On your machine');
        $this->describeCommands(self::LOCAL);

        $this->section('You and Rocket');
        $this->describeCommands(self::ACCOUNT);

        $items = collect($grouped)->collapse();
        $example = $items->has('servers') ? 'servers' : (string) $items->keys()->first();

        $this->newLine();
        $this->tip("rocket {$example}", "every {$this->singular($example)} command, and whether you may run it");
        $this->tip('rocket hub', 'click through it all, it asks for what it needs');
        $this->tip('rocket <command> --help', 'arguments and options; add --json to any command for scripts');
        $this->newLine();

        return self::SUCCESS;
    }

    /** @param array<string, mixed>|null $team */
    private function header(bool $signedIn, ?array $team, int $operations): void
    {
        $where = match (true) {
            ! $signedIn => '<fg=yellow>not signed in</> <fg=gray>· rocket setup-token \\<token></>',
            $team === null => '<fg=yellow>no team picked</> <fg=gray>· rocket team</>',
            default => "<fg=green>{$team['name']}</> <fg=gray>({$team['slug']})</>",
        };

        $this->newLine();
        $this->line('  🚀 <options=bold>Rocket</> <fg=gray>'.config('app.version')."</>  ·  {$where}  ·  <fg=gray>{$operations} API commands</>");
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('  <fg=yellow;options=bold>'.Str::upper($title).'</>');
    }

    /**
     * @param  array<int, Operation>  $operations
     * @param  array<string, mixed>|null  $team
     */
    private function actions(string $item, array $operations, PermissionGate $gate, ?array $team): string
    {
        $direct = collect($operations)
            ->filter(fn (Operation $operation): bool => substr_count($operation->command, ':') === 1)
            ->map(fn (Operation $operation): string => $operation->action())
            ->unique()
            ->sortBy(fn (string $action): int => in_array($action, self::LEADING_ACTIONS, true) ? (int) array_search($action, self::LEADING_ACTIONS, true) : count(self::LEADING_ACTIONS))
            ->values();

        $shown = $direct->take(self::ACTIONS_SHOWN);
        $more = count($operations) - $shown->count();
        $locked = collect($operations)->reject(fn (Operation $operation): bool => $gate->allows($operation, $team))->count();

        return trim($shown->implode(' ')
            .($more > 0 ? " <fg=gray>+{$more}</>" : '')
            .($locked > 0 ? " <fg=yellow>🔒 {$locked}</>" : ''));
    }

    /** @param array<int, string> $names */
    private function describeCommands(array $names): void
    {
        foreach ($names as $name) {
            if ($this->getApplication()?->has($name)) {
                $this->components->twoColumnDetail("<fg=cyan>{$name}</>", '<fg=gray>'.$this->getApplication()->find($name)->getDescription().'</>');
            }
        }
    }

    private function tip(string $command, string $explanation): void
    {
        $this->line('  <fg=magenta>→</> <fg=cyan>'.str_replace('<', '\\<', str_pad($command, 26)).'</> <fg=gray>'.$explanation.'</>');
    }

    private function singular(string $item): string
    {
        return Str::lower(Str::headline(Str::singular($item)));
    }
}
