<?php

namespace App\Commands;

use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\ResolvesTeam;
use App\Schema\Operation;
use App\Schema\SchemaCache;
use App\Support\PermissionGate;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\search;

/**
 * The interactive way in: pick a team, a resource and an action, and the action asks for the rest.
 * Actions the team does not allow are listed with a lock and explained instead of run.
 */
class Hub extends Command
{
    use OutputsJson;
    use ResolvesTeam;

    private const string SWITCH_TEAM = '__switch_team';

    private const string QUIT = '__quit';

    private const string BACK = '__back';

    protected $signature = 'hub';

    protected $description = 'Browse and run everything the API offers, interactively';

    public function handle(SchemaCache $schema, PermissionGate $gate): int
    {
        if (! $this->canPrompt()) {
            return $this->call('home', $this->wantsJson() ? ['--json' => true] : []);
        }

        $team = $this->resolveTeam();

        while (true) {
            $item = $this->chooseItem($schema, $team);

            if ($item === self::QUIT) {
                return self::SUCCESS;
            }

            if ($item === self::SWITCH_TEAM) {
                $team = $this->chooseTeam(fresh: true);

                continue;
            }

            $operation = $this->chooseOperation($schema, $gate, $team, $item);

            if ($operation === null) {
                continue;
            }

            if (! $gate->allows($operation, $team)) {
                $this->newLine();
                $this->components->warn($gate->denial((string) $operation->permission, $team['name'], $operation->permissionLabel)->getMessage());
                $this->line('  <fg=gray>Ask a team owner for a role that includes it. If your Rocket CLI token is narrowed, widen it under Settings, API.</>');

                continue;
            }

            $this->call($operation->command, ['--team' => $team['slug']]);

            $this->newLine();

            if (! confirm(label: 'Do something else?', default: true)) {
                return self::SUCCESS;
            }
        }
    }

    /** @param array<string, mixed> $team */
    private function chooseItem(SchemaCache $schema, array $team): string
    {
        $items = collect($schema->operations())
            ->groupBy(fn (Operation $operation): string => $operation->item)
            ->map(fn ($operations, string $item): string => Str::headline($item).' · '.$operations->count().' actions')
            ->sortKeys()
            ->all();

        $options = [
            ...$items,
            self::SWITCH_TEAM => "Switch team · now {$team['name']}",
            self::QUIT => 'Quit',
        ];

        return (string) search(
            label: "What do you want to work on in {$team['name']}?",
            options: fn (string $query): array => array_filter($options, fn (string $label, string $key): bool => $query === '' || Str::contains(Str::lower($key.' '.$label), Str::lower($query)), ARRAY_FILTER_USE_BOTH),
            placeholder: 'Type to search',
            scroll: 15,
            hint: 'Type to filter, ↓ to choose, enter to open',
        );
    }

    /** @param array<string, mixed> $team */
    private function chooseOperation(SchemaCache $schema, PermissionGate $gate, array $team, string $item): ?Operation
    {
        $operations = collect($schema->operations())->filter(fn (Operation $operation): bool => $operation->item === $item);

        $options = $operations
            ->mapWithKeys(fn (Operation $operation): array => [
                $operation->command => ($gate->allows($operation, $team) ? '' : '🔒 ').$operation->summary.' · '.Str::after($operation->command, $item.':'),
            ])
            ->all();

        $chosen = (string) search(
            label: Str::headline($item).': which action?',
            options: fn (string $query): array => [
                ...array_filter($options, fn (string $label, string $command): bool => $query === '' || Str::contains(Str::lower($command.' '.$label), Str::lower($query)), ARRAY_FILTER_USE_BOTH),
                self::BACK => '← Back',
            ],
            placeholder: 'Type to search',
            scroll: 15,
            hint: 'Type to filter, ↓ to choose, enter to run',
        );

        return $chosen === self::BACK ? null : $schema->find($chosen);
    }
}
