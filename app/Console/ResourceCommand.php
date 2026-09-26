<?php

namespace App\Console;

use App\Commands\Concerns\OutputsJson;
use App\Schema\Operation;
use App\Support\PermissionGate;
use App\Support\Teams;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** `rocket servers`: every command of one API resource with its arguments, grouped by sub-resource, marked ✓ or 🔒 for the current team. */
class ResourceCommand extends Command
{
    use OutputsJson;

    /** @param array<int, Operation> $operations */
    public function __construct(private readonly string $item, private readonly array $operations)
    {
        $this->name = $item;
        $this->description = 'Every '.Str::lower(Str::headline(Str::singular($item))).' command, and whether you may run it';

        parent::__construct();
    }

    public function handle(PermissionGate $gate, Teams $teams): int
    {
        $team = $teams->currentFromCache();

        if ($this->wantsJson()) {
            return $this->emitJson(array_map(fn (Operation $operation): array => [
                'command' => $operation->command,
                'summary' => $operation->summary,
                'arguments' => $operation->pathParameters(),
                'permission' => $operation->permission,
                'allowed' => $gate->allows($operation, $team),
            ], $this->operations));
        }

        $sections = collect($this->operations)->groupBy(fn (Operation $operation): string => substr_count($operation->command, ':') > 1
            ? explode(':', $operation->command)[1]
            : '');

        $this->newLine();
        $this->line('  🚀 <options=bold>'.Str::headline($this->item).'</>'.($team === null ? '' : "  <fg=gray>in {$team['name']}</>"));

        foreach ($sections->sortKeys() as $section => $operations) {
            if ($section !== '') {
                $this->newLine();
                $this->line('  <fg=yellow;options=bold>'.Str::upper(Str::headline($section)).'</>');
            }

            foreach ($operations as $operation) {
                $allowed = $gate->allows($operation, $team);

                $this->components->twoColumnDetail(
                    ($allowed ? '<fg=cyan>' : '<fg=gray>').$operation->command.'</> '.$this->usage($operation),
                    $allowed ? $operation->summary : "<fg=yellow>🔒 {$operation->permission}</>",
                );
            }
        }

        $this->newLine();
        $this->line('  <fg=gray>Leave an argument out and Rocket lets you search for it. Slugs and names work as well as ids.</>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function usage(Operation $operation): string
    {
        return collect($operation->pathParameters())
            ->map(fn (string $parameter): string => '<fg=gray>{'.Str::kebab($parameter).'}</>')
            ->implode(' ');
    }
}
