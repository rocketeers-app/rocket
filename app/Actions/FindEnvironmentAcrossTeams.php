<?php

namespace App\Actions;

use App\Exceptions\ApiException;
use App\Exceptions\StepException;
use App\Schema\SchemaCache;
use App\Support\PermissionGate;
use App\Support\RecordFinder;
use App\Support\Records;
use App\Support\Teams;
use Lorisleiva\Actions\Concerns\AsAction;

use function Laravel\Prompts\select;

class FindEnvironmentAcrossTeams
{
    use AsAction;

    /** @return array{team: array<string, mixed>, environment: array<string, mixed>} */
    public function handle(string $slug, bool $interactive, ?string $team = null): array
    {
        $list = app(SchemaCache::class)->findByRoute('api.team.environments.index')
            ?? throw new StepException('This version of the API has no environments list. Run `rocket api:refresh`.');

        $teams = collect(app(Teams::class)->all())
            ->filter(fn (array $candidate): bool => $team === null || in_array($team, [$candidate['slug'], $candidate['name'], $candidate['id']], true))
            ->filter(fn (array $candidate): bool => app(PermissionGate::class)->allows($list, $candidate));

        $matches = $teams
            ->flatMap(fn (array $candidate): array => collect(app(RecordFinder::class)->search($list, ['team' => (string) $candidate['slug']], $slug)['records'])
                ->filter(fn (array $environment): bool => ($environment['slug'] ?? null) === $slug || Records::id($environment) === $slug)
                ->map(fn (array $environment): array => ['team' => $candidate, 'environment' => $environment])
                ->all())
            ->values();

        if ($matches->isEmpty()) {
            throw new ApiException("No environment `{$slug}` in any of your teams.", 404);
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
}
