<?php

namespace App\Actions;

use App\Exceptions\ApiException;
use App\Exceptions\StepException;
use App\Schema\Operation;
use App\Schema\SchemaCache;
use App\Support\PermissionGate;
use App\Support\RecordFinder;
use App\Support\Records;
use App\Support\Teams;
use Lorisleiva\Actions\Concerns\AsAction;

use function Laravel\Prompts\search;
use function Laravel\Prompts\select;

class FindEnvironmentAcrossTeams
{
    use AsAction;

    /** @return array{team: array<string, mixed>, environment: array<string, mixed>} */
    public function handle(?string $slug, bool $interactive, ?array $team = null): array
    {
        $list = app(SchemaCache::class)->findByRoute('api.team.environments.index')
            ?? throw new StepException('This version of the API has no environments list. Run `rocket api:refresh`.');

        $teams = collect($team === null ? app(Teams::class)->all() : [$team])
            ->filter(fn (array $candidate): bool => app(PermissionGate::class)->allows($list, $candidate))
            ->values()
            ->all();

        if (blank($slug)) {
            if (! $interactive) {
                throw new ApiException('Missing the environment.', 422, errors: ['environment' => ['Pass the environment slug as an argument: rocket <command> <environment>.']]);
            }

            return $this->pick($list, $teams);
        }

        $matches = collect($teams)
            ->flatMap(fn (array $candidate): array => collect($this->environmentsIn($list, $candidate, $slug))
                ->map(fn (array $environment): array => ['team' => $candidate, 'environment' => $environment])
                ->all())
            ->values();

        if ($matches->isEmpty()) {
            if (! $interactive) {
                throw new ApiException("No environment `{$slug}` in any of your teams.", 404);
            }

            return $this->pick($list, $teams, hint: "No environment `{$slug}` in your teams");
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if (! $interactive) {
            throw new ApiException("`{$slug}` exists in several teams: ".$matches->map(fn (array $match): string => $match['team']['slug'])->implode(', ').'. Pass --team= to pick one.', 409);
        }

        $index = select(
            label: "Which {$slug}?",
            options: $matches->mapWithKeys(fn (array $match, int $index): array => ["#{$index}" => "{$match['environment']['name']} · team {$match['team']['name']}"])->all(),
        );

        return $matches[(int) ltrim((string) $index, '#')];
    }

    private function pick(Operation $list, array $teams, string $hint = ''): array
    {
        $found = [];

        $options = function (string $query) use ($list, $teams, &$found): array {
            $options = [];

            foreach ($teams as $index => $team) {
                foreach (app(RecordFinder::class)->search($list, ['team' => (string) $team['slug']], $query)['records'] as $environment) {
                    $id = Records::id($environment);

                    if ($id === null) {
                        continue;
                    }

                    $found["{$index}:{$id}"] = ['team' => $team, 'environment' => $environment];
                    $options["{$index}:{$id}"] = Records::display($environment).(count($teams) > 1 ? " · team {$team['name']}" : '');
                }
            }

            return $options;
        };

        $first = $options('');

        if ($first === []) {
            throw new ApiException('There is nothing to choose for Environment yet.', 404);
        }

        $key = search(
            label: 'Environment',
            options: fn (string $query): array => $query === '' ? $first : $options($query),
            placeholder: 'Type to search',
            scroll: 10,
            hint: $hint,
            required: true,
        );

        return $found[(string) $key];
    }

    /**
     * @param  array<string, mixed>  $team
     * @return array<int, array<string, mixed>>
     */
    private function environmentsIn(Operation $list, array $team, string $slug): array
    {
        return array_values(array_filter(
            app(RecordFinder::class)->all($list, ['team' => (string) $team['slug']]),
            fn (array $environment): bool => ($environment['slug'] ?? null) === $slug,
        ));
    }
}
