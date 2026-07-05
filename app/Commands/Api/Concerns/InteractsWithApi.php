<?php

namespace App\Commands\Api\Concerns;

use App\Actions\ApiRequest;
use App\Actions\Credentials;
use App\Exceptions\StepException;

use function Laravel\Prompts\error;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Shared API-command plumbing: team/scope resolution, path building, and a
 * single rendering path for --json / --metadata / table output. Used by the
 * generic BaseApiCommand and by the standalone commands (Get, Me, Teams).
 */
trait InteractsWithApi
{
    /**
     * Path placeholders that can be supplied as flags, in signature order.
     */
    protected array $scopes = ['id', 'server', 'environment', 'domain'];

    /**
     * The team slug from --team, falling back to the configured default.
     */
    protected function resolveTeam(): string
    {
        $team = $this->option('team') ?: Credentials::make()->defaultTeam();

        if (blank($team)) {
            throw new StepException('No team given. Pass --team=<slug> or set a default with: rocket use-team <slug>');
        }

        return $team;
    }

    /**
     * Provided scope flags as [scope => value], preserving $scopes order.
     * --env is an alias of --environment.
     */
    protected function scopeInputs(): array
    {
        $inputs = [];

        foreach ($this->scopes as $scope) {
            $value = $this->option($scope);

            if ($scope === 'environment' && blank($value)) {
                $value = $this->option('env');
            }

            if (filled($value)) {
                $inputs[$scope] = $value;
            }
        }

        return $inputs;
    }

    /**
     * Sorted, pipe-joined signature of the provided scope flags (e.g. "environment|id").
     */
    protected function scopeSignature(): string
    {
        $provided = array_keys($this->scopeInputs());
        sort($provided);

        return implode('|', $provided);
    }

    /**
     * Substitute {team} and every provided scope placeholder into a path template.
     */
    protected function fillPath(string $template, string $team): string
    {
        $replacements = ['{team}' => $team];

        foreach ($this->scopeInputs() as $scope => $value) {
            $replacements['{'.$scope.'}'] = $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * Resolve the `path` argument for a raw verb command: absolute paths (leading
     * "/") are hit as-is; otherwise the resolved team is prefixed once.
     */
    protected function resolvePath(): string
    {
        $raw = $this->argument('path');

        if (str_starts_with($raw, '/')) {
            return ltrim($raw, '/');
        }

        $team = $this->resolveTeam();
        $raw = ltrim($raw, '/');

        if ($raw === $team || str_starts_with($raw, $team.'/')) {
            return $raw;
        }

        return $team.'/'.$raw;
    }

    /**
     * Resolve a team slug to its ID (for body fields like team_id), via me/teams.
     * Falls back to the slug if it can't be resolved.
     */
    protected function resolveTeamId(string $slug): string
    {
        foreach (ApiRequest::make()->paginated('me/teams') as $team) {
            if (($team['slug'] ?? null) === $slug) {
                return $team['id'] ?? $slug;
            }
        }

        return $slug;
    }

    /**
     * Build a request body from --data (JSON) merged with repeatable --field key=value.
     */
    protected function bodyFromOptions(): array
    {
        $body = [];

        if ($data = $this->option('data')) {
            $decoded = json_decode($data, true);

            if (! is_array($decoded)) {
                throw new StepException('--data must be a valid JSON object.');
            }

            $body = $decoded;
        }

        foreach ((array) $this->option('field') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);

            if ($value === null || $key === '') {
                throw new StepException("Invalid --field '{$pair}'. Use key=value.");
            }

            $body[$key] = $this->castFieldValue($value);
        }

        return $body;
    }

    /**
     * Best-effort scalar coercion for --field values (true/false/null/int).
     */
    private function castFieldValue(string $value): mixed
    {
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => ctype_digit($value) ? (int) $value : $value,
        };
    }

    protected function outputJson(mixed $result): int
    {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Uniform failure output — raw JSON error object under --json (so it stays
     * pipeable), else a Laravel Prompts error block.
     */
    protected function respondWithError(string $message): int
    {
        if ($this->hasOption('json') && $this->option('json')) {
            $this->line(json_encode(['error' => $message], JSON_THROW_ON_ERROR));
        } else {
            error($message);
        }

        return self::FAILURE;
    }

    /**
     * Render a list of records as a table using a registry column map.
     *
     * @param  array<string, string|array>  $columns  Header => 'field' | ['field', 'format', ...args]
     */
    protected function renderList(array $items, array $columns, string $emptyLabel = 'records'): void
    {
        if (empty($items)) {
            warning('No '.$emptyLabel.' found.');

            return;
        }

        $rows = array_map(
            fn ($item) => array_map(fn ($spec) => $this->formatColumn($item, $spec), $columns),
            $items
        );

        table(array_keys($columns), $rows);
    }

    /**
     * Render a single record as a Field/Value table (scalar fields only).
     */
    protected function renderSingle(array $item): void
    {
        $rows = [];

        foreach ($item as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $rows[] = [$key, $this->scalar($value)];
        }

        table(['Field', 'Value'], $rows);
    }

    /**
     * @param  string|array  $spec  'field' | ['field', 'format', ...args]
     */
    protected function formatColumn(array $item, string|array $spec): string
    {
        if (is_string($spec)) {
            return $this->scalar(data_get($item, $spec));
        }

        $value = data_get($item, $spec[0]);
        $format = $spec[1] ?? 'plain';

        return match ($format) {
            'money' => filled($value) ? '€'.number_format((float) $value, 2) : '-',
            'bool' => $value ? 'Yes' : 'No',
            'count' => (string) ($value ?? 0),
            'limit' => str((string) ($value ?? ''))->limit($spec[2] ?? 50)->toString(),
            default => $this->scalar($value),
        };
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return ($value === null || $value === '') ? '-' : (string) $value;
    }
}
