<?php

namespace App\Support;

use App\Actions\SaveSettings;
use App\Actions\SendApiRequest;
use App\Api\Requests\GetMyTeams;
use App\Exceptions\StepException;
use Illuminate\Support\Facades\Storage;

/**
 * The teams the token reaches, with what it may do in each, cached for a few minutes so a permission
 * check does not cost a request. The cache belongs to one token and is dropped when the token changes.
 */
class Teams
{
    public const string PATH = 'cache/teams.json';

    /** @var array{token: string, fetched_at: int, teams: array<int, array<string, mixed>>}|false|null */
    private array|false|null $cache = false;

    /** @return array<int, array<string, mixed>> */
    public function all(bool $fresh = false): array
    {
        $cache = $this->read();

        if (! $fresh && $cache !== null && $cache['fetched_at'] >= time() - (int) config('rocketeers.teams_max_age')) {
            return $cache['teams'];
        }

        $teams = collect((new SendApiRequest)(new GetMyTeams)->json('data') ?? [])
            ->map(fn (array $team): array => array_intersect_key($team, array_flip(['id', 'name', 'slug', 'permissions'])))
            ->values()
            ->all();

        $this->cache = [
            'token' => $this->tokenHash(),
            'fetched_at' => time(),
            'teams' => $teams,
        ];

        Storage::put(self::PATH, (string) json_encode($this->cache, JSON_UNESCAPED_SLASHES));

        return $teams;
    }

    /** @return array<int, array<string, mixed>>|null */
    public function cached(): ?array
    {
        return $this->read()['teams'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function find(string $identifier, bool $fresh = false): ?array
    {
        $needle = mb_strtolower($identifier);

        return collect($this->all($fresh))->first(fn (array $team): bool => in_array($needle, [
            mb_strtolower((string) $team['slug']),
            mb_strtolower((string) $team['name']),
            (string) $team['id'],
        ], true));
    }

    /** @return array<string, mixed>|null */
    public function current(?string $override = null): ?array
    {
        $identifier = $override ?: config('rocketeers.default_team');

        if (blank($identifier)) {
            return null;
        }

        $team = $this->find((string) $identifier) ?? $this->find((string) $identifier, fresh: true);

        if ($team === null) {
            throw new StepException("Team `{$identifier}` is not one of your teams. Run `rocket team` to pick one.");
        }

        return $team;
    }

    /** @return array<string, mixed>|null */
    public function currentFromCache(): ?array
    {
        $identifier = config('rocketeers.default_team');

        return blank($identifier) ? null : collect($this->cached() ?? [])->firstWhere('slug', $identifier);
    }

    /** @param array<string, mixed> $team */
    public function select(array $team): void
    {
        (new SaveSettings)(['DEFAULT_TEAM' => (string) $team['slug']]);
    }

    public function forget(): void
    {
        Storage::delete(self::PATH);

        $this->cache = false;
    }

    /** @return array{token: string, fetched_at: int, teams: array<int, array<string, mixed>>}|null */
    private function read(): ?array
    {
        if ($this->cache === false) {
            $cache = Storage::exists(self::PATH) ? json_decode((string) Storage::get(self::PATH), true) : null;
            $this->cache = is_array($cache) ? $cache : null;
        }

        return ($this->cache['token'] ?? null) === $this->tokenHash() ? $this->cache : null;
    }

    private function tokenHash(): string
    {
        return hash('sha256', (string) config('rocketeers.api_token'));
    }
}
