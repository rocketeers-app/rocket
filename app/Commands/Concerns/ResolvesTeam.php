<?php

namespace App\Commands\Concerns;

use App\Exceptions\StepException;
use App\Exceptions\UnknownTeamException;
use App\Support\Teams;

use function Laravel\Prompts\select;

/**
 * The team a command acts in: --team, else the saved default, else — when someone is there to answer — a pick that is saved.
 * A team the token does not reach is picked again when someone can answer; a --team picked that way is not saved.
 */
trait ResolvesTeam
{
    use EnsuresToken;

    /** @return array<string, mixed> */
    protected function resolveTeam(): array
    {
        $this->ensureToken();

        $override = $this->teamOverride();

        try {
            $team = app(Teams::class)->current($override);
        } catch (UnknownTeamException $exception) {
            return $this->chooseTeamInstead($exception, save: $override === null);
        }

        if ($team !== null) {
            return $team;
        }

        if (! $this->canPrompt()) {
            throw new StepException('No team selected. Run `rocket team` to pick one, or pass --team=.');
        }

        return $this->chooseTeam();
    }

    protected function teamFilter(): ?array
    {
        $override = $this->teamOverride();

        if ($override === null) {
            return null;
        }

        try {
            return app(Teams::class)->current($override);
        } catch (UnknownTeamException $exception) {
            return $this->chooseTeamInstead($exception, save: false);
        }
    }

    /** @return array<string, mixed> */
    protected function chooseTeam(bool $fresh = false, bool $save = true): array
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

        if ($save) {
            $teams->select($team);
        }

        return $team;
    }

    private function teamOverride(): ?string
    {
        $override = $this->input->hasOption('team') ? $this->option('team') : null;

        return blank($override) ? null : (string) $override;
    }

    private function chooseTeamInstead(UnknownTeamException $exception, bool $save): array
    {
        if (! $this->canPrompt()) {
            throw $exception;
        }

        $this->components->warn("Team `{$exception->identifier}` is not one of your teams.");

        return $this->chooseTeam(fresh: true, save: $save);
    }
}
