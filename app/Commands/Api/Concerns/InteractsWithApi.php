<?php

namespace App\Commands\Api\Concerns;

use App\Actions\Credentials;
use App\Exceptions\StepException;

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

    protected function outputJson(mixed $result): int
    {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Uniform failure output — JSON error object under --json, else a clean error line.
     */
    protected function respondWithError(string $message): int
    {
        if ($this->hasOption('json') && $this->option('json')) {
            $this->line(json_encode(['error' => $message], JSON_THROW_ON_ERROR));
        } else {
            $this->newLine();
            $this->error($message);
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
            $this->newLine();
            $this->warn('No '.$emptyLabel.' found.');

            return;
        }

        $rows = array_map(
            fn ($item) => array_map(fn ($spec) => $this->formatColumn($item, $spec), $columns),
            $items
        );

        $this->newLine();
        $this->table(array_keys($columns), $rows);
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

        $this->newLine();
        $this->table(['Field', 'Value'], $rows);
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
