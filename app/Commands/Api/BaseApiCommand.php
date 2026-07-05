<?php

namespace App\Commands\Api;

use App\Actions\ApiRequest;
use App\Commands\Api\Concerns\InteractsWithApi;
use App\Exceptions\StepException;
use Illuminate\Console\Command;

/**
 * Generic, registry-driven command for every GET endpoint. A concrete resource
 * command only declares `protected string $resource`; this base builds the
 * signature, resolves the team + scope flags to a route from config/rocket_api.php,
 * fetches it, and renders JSON or a table.
 */
abstract class BaseApiCommand extends Command
{
    use InteractsWithApi;

    /**
     * Registry key in config/rocket_api.php (set by the concrete subclass).
     */
    protected string $resource;

    public function __construct()
    {
        $this->signature = sprintf(
            '%s '.
            '{--team= : Team slug (defaults to your configured team)} '.
            '{--id= : Resource ID for a single record} '.
            '{--server= : Scope to a server} '.
            '{--environment= : Scope to an environment} '.
            '{--env= : Alias of --environment} '.
            '{--domain= : Scope to a domain} '.
            '{--page= : Fetch a single page instead of all} '.
            '{--per-page= : Items per page (max 50)} '.
            '{--json : Output raw JSON} '.
            '{--metadata : Include results, meta and served_in in JSON output}',
            $this->resource
        );

        $this->description = config("rocket_api.resources.{$this->resource}.description", 'List '.$this->resource);

        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $definition = $this->resource();
            $team = $this->resolveTeam();
            $route = $this->resolveRoute($definition);
            $path = $this->fillPath($route['path'], $team);

            return $this->deliver($route['envelope'], $path, $definition);
        } catch (StepException $e) {
            return $this->respondWithError($e->getMessage());
        }
    }

    protected function resource(): array
    {
        $definition = config("rocket_api.resources.{$this->resource}");

        if (! is_array($definition)) {
            throw new StepException("Unknown API resource '{$this->resource}'.");
        }

        return $definition;
    }

    /**
     * Match the provided scope flags to a registered route, or explain what's supported.
     *
     * @return array{path: string, envelope: string}
     */
    protected function resolveRoute(array $definition): array
    {
        $signature = $this->scopeSignature();
        $route = $definition['routes'][$signature] ?? null;

        if ($route === null) {
            throw new StepException($this->unsupportedScopeMessage($definition['routes']));
        }

        if (is_array($route)) {
            return ['path' => $route['path'], 'envelope' => $route['envelope'] ?? 'paginated'];
        }

        return ['path' => $route, 'envelope' => str_contains($signature, 'id') ? 'single' : 'paginated'];
    }

    protected function deliver(string $envelope, string $path, array $definition): int
    {
        return match ($envelope) {
            'single' => $this->deliverSingle($path),
            'custom' => $this->deliverCustom($path),
            default => $this->deliverPaginated($path, $definition),
        };
    }

    protected function deliverPaginated(string $path, array $definition): int
    {
        $perPage = (int) ($this->option('per-page') ?: 50);
        $page = $this->option('page') !== null ? (int) $this->option('page') : null;

        if ($this->option('json')) {
            return $this->outputJson(
                ApiRequest::make()->paginated($path, (bool) $this->option('metadata'), $perPage, $page)
            );
        }

        $items = ApiRequest::make()->paginated($path, false, $perPage, $page);
        $this->renderList($items, $definition['columns'] ?? [], $this->resource);

        return self::SUCCESS;
    }

    protected function deliverSingle(string $path): int
    {
        $item = ApiRequest::make()->single($path);

        if ($this->option('json')) {
            return $this->outputJson($item);
        }

        $this->renderSingle($item);

        return self::SUCCESS;
    }

    protected function deliverCustom(string $path): int
    {
        $data = ApiRequest::make()->get($path);

        if ($this->option('json')) {
            return $this->outputJson($data);
        }

        $this->renderCustom($data);

        return self::SUCCESS;
    }

    /**
     * Default custom renderer — pretty JSON. Bespoke commands (dns, finances,
     * services) override this to draw tailored tables.
     */
    protected function renderCustom(array $data): void
    {
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function unsupportedScopeMessage(array $routes): string
    {
        $hints = array_map(function (string $signature) {
            if ($signature === '') {
                return '  (team only)';
            }

            $parts = array_map(fn ($scope) => '--'.$scope.'=<value>', explode('|', $signature));

            return '  '.implode(' ', $parts);
        }, array_keys($routes));

        return "'{$this->resource}' doesn't support that combination of scopes. Available:".PHP_EOL.implode(PHP_EOL, $hints);
    }
}
