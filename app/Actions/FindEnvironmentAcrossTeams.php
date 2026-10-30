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

        $found = $this->searchTeams($list, $teams, $slug);

        $matches = collect($found)
            ->filter(fn (array $match): bool => ($match['environment']['slug'] ?? null) === $slug)
            ->values();

        if ($matches->isEmpty()) {
            if (! $interactive) {
                throw new ApiException("No environment `{$slug}` in any of your teams.", 404);
            }

            return $this->pick($list, $teams, hint: "No environment `{$slug}` in your teams", similar: $found);
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

    private function pick(Operation $list, array $teams, string $hint = '', array $similar = []): array
    {
        $found = [];

        $options = function (array $matches) use ($teams, &$found): array {
            $options = [];

            foreach ($matches as $key => $match) {
                $found[$key] = $match;
                $options[$key] = Records::display($match['environment']).(count($teams) > 1 ? " · team {$match['team']['name']}" : '');
            }

            return $options;
        };

        $first = $options($similar === [] ? $this->searchTeams($list, $teams, '') : $similar);

        if ($first === []) {
            throw new ApiException('There is nothing to choose for Environment yet.', 404);
        }

        $key = search(
            label: 'Environment',
            options: fn (string $query): array => $query === '' ? $first : $options($this->searchTeams($list, $teams, $query)),
            placeholder: 'Type to search',
            scroll: 10,
            hint: $hint,
            required: true,
        );

        return $found[(string) $key];
    }

    private function searchTeams(Operation $list, array $teams, string $query): array
    {
        $results = app(RecordFinder::class)->searchEach(
            $list,
            array_map(fn (array $team): array => ['team' => (string) $team['slug']], $teams),
            $query,
        );

        $found = [];

        foreach ($results as $index => $result) {
            foreach ($result['records'] as $environment) {
                $id = Records::id($environment);

                if ($id !== null) {
                    $found["{$index}:{$id}"] = ['team' => $teams[$index], 'environment' => $environment];
                }
            }
        }

        return $found;
    }
}
