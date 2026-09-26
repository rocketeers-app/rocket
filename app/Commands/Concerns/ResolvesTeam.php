<?php

namespace App\Commands\Concerns;

use App\Api\ApiErrorPresenter;
use App\Exceptions\StepException;
use App\Support\Teams;

use function Laravel\Prompts\select;

/** The team a command acts in: --team, else the saved default, else — when someone is there to answer — a pick that is saved. */
trait ResolvesTeam
{
    /** @return array<string, mixed> */
    protected function resolveTeam(): array
    {
        if (blank(config('rocketeers.api_token'))) {
            throw ApiErrorPresenter::missingToken();
        }

        $override = $this->input->hasOption('team') ? $this->option('team') : null;
        $team = app(Teams::class)->current($override);

        if ($team !== null) {
            return $team;
        }

        if (! $this->canPrompt()) {
            throw new StepException('No team selected. Run `rocket team` to pick one, or pass --team=.');
        }

        return $this->chooseTeam();
    }

    /** @return array<string, mixed> */
    protected function chooseTeam(bool $fresh = false): array
    {
        $teams = app(Teams::class);
        $all = $teams->all($fresh);

        if ($all === []) {
            throw new StepException('Your token does not reach any team.');
        }

        if (count($all) === 1 || ! $this->canPrompt()) {
            $team = $all[0];
        } else {
            $current = config('rocketeers.default_team');
            $slug = select(
                label: 'Which team do you want to work in?',
                options: collect($all)->mapWithKeys(fn (array $team): array => [$team['slug'] => $team['name']])->all(),
                default: collect($all)->contains('slug', $current) ? $current : null,
                scroll: 12,
            );
            $team = collect($all)->firstWhere('slug', $slug);
        }

        $teams->select($team);

        return $team;
    }
}
