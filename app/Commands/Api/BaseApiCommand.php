<?php

namespace App\Commands\Api;

use App\Actions\ApiRequest;
use App\Commands\Api\Concerns\InteractsWithApi;
use App\Exceptions\StepException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Prompts\Exceptions\NonInteractiveValidationException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Generic, registry-driven command for every endpoint of a resource. A concrete
 * resource command only declares `protected string $resource`; this base builds
 * the signature, resolves the team + scope flags to a route from
 * config/rocket_api.php, and either reads (GET) or writes (--create/--update/
 * --delete/--action) it, rendering JSON or a table.
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
            '{--create : Create a new record} '.
            '{--update : Update the --id record} '.
            '{--delete : Delete the --id record} '.
            '{--action= : Run a named write action (see --help)} '.
            '{--F|field=* : Body field as key=value (repeatable)} '.
            '{--data= : Raw JSON body} '.
            '{--force : Skip the destructive-action confirmation} '.
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

            if ($action = $this->requestedAction()) {
                return $this->write($definition, $action);
            }

            $team = $this->resolveTeam();
            $route = $this->resolveRoute($definition);
            $path = $this->fillPath($route['path'], $team);

            return $this->deliver($route['envelope'], $path, $definition);
        } catch (StepException $e) {
            return $this->respondWithError($e->getMessage());
        }
    }

    /**
     * The write action requested via flags, or null for a read.
     */
    protected function requestedAction(): ?string
    {
        return match (true) {
            (bool) $this->option('create') => 'create',
            (bool) $this->option('update') => 'update',
            (bool) $this->option('delete') => 'delete',
            filled($this->option('action')) => $this->option('action'),
            default => null,
        };
    }

    protected function write(array $definition, string $action): int
    {
        $writes = $definition['writes'] ?? [];

        if (! isset($writes[$action])) {
            throw new StepException($this->unknownActionMessage($writes, $action));
        }

        $spec = $writes[$action];
        $team = $this->resolveTeam();
        $route = $this->resolveWriteRoute($spec['routes'], $action);
        $path = $this->fillPath($route, $team);
        $body = $this->buildBody($spec, $team);

        if (($spec['confirm'] ?? false) && ! $this->option('force')
            && ! confirm($spec['method'].' '.$path.' — are you sure?', default: false)) {
            note('Aborted.');

            return self::SUCCESS;
        }

        $method = strtolower($spec['method']);
        $result = ApiRequest::make()->{$method}($path, $body);

        if ($this->option('json')) {
            return $this->outputJson($result);
        }

        $data = $result['data'] ?? $result;

        if (empty($data)) {
            info(ucfirst($action).' — done.');
        } elseif (array_is_list($data)) {
            note(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderSingle($data);
        }

        return self::SUCCESS;
    }

    /**
     * Match provided scope flags to a write route, or explain what's supported.
     */
    protected function resolveWriteRoute(array $routes, string $action): string
    {
        $signature = $this->scopeSignature();

        if (! isset($routes[$signature])) {
            throw new StepException($this->unsupportedScopeMessage($routes, "'{$this->resource} --action={$action}'"));
        }

        return $routes[$signature];
    }

    /**
     * Assemble the request body: --field/--data, then prompt for any missing
     * required or "guided" field (one with options / a linked resource), then
     * apply injected context values (e.g. team_id).
     */
    protected function buildBody(array $spec, string $team): array
    {
        $body = $this->bodyFromOptions();

        foreach ($spec['fields'] ?? [] as $name => $meta) {
            $required = $meta['required'] ?? false;
            $guided = isset($meta['options']) || isset($meta['from']) || isset($meta['from_field']);

            if (array_key_exists($name, $body) || (! $required && ! $guided)) {
                continue;
            }

            try {
                $value = $this->promptField($name, $meta, $team, $body);
            } catch (NonInteractiveValidationException) {
                if ($required) {
                    throw new StepException("Missing required field '{$name}'. Pass it with -F {$name}=<value>.");
                }

                continue;
            }

            if ($value !== null && $value !== []) {
                $body[$name] = $value;
            } elseif ($required) {
                throw new StepException("Field '{$name}' is required.");
            }
        }

        foreach ($spec['inject'] ?? [] as $key => $source) {
            if (! array_key_exists($key, $body)) {
                $body[$key] = $source === 'team' ? $this->resolveTeamId($team) : $source;
            }
        }

        return $body;
    }

    /**
     * Prompt for a single field: an enum select (`options`), a resource-backed
     * select/multiselect (`from`/`from_field`), a yes/no (`bool`), or free text.
     */
    protected function promptField(string $name, array $meta, string $team, array $body): mixed
    {
        $label = $this->fieldLabel($name);
        $required = $meta['required'] ?? false;
        $hint = $meta['hint'] ?? '';

        if ($options = $meta['options'] ?? null) {
            return $this->pick($label, array_combine($options, $options), $required, $hint);
        }

        if ($resource = $this->fieldResource($meta, $body)) {
            $choices = $this->resourceChoices($resource, $team, $meta, $body);

            if (empty($choices)) {
                if ($required) {
                    throw new StepException("No {$resource} available to choose for '{$name}'.");
                }

                return null;
            }

            if ($meta['multiple'] ?? false) {
                return multiselect($label, $choices, required: $required, hint: $hint);
            }

            return $this->pick($label, $choices, $required, $hint);
        }

        if (($meta['type'] ?? null) === 'bool') {
            return confirm($label, default: false);
        }

        $value = text($label, required: $required, hint: $hint);

        return ($meta['type'] ?? null) === 'int' && $value !== '' ? (int) $value : ($value === '' ? null : $value);
    }

    /**
     * A single select, with a "(skip)" entry prepended when the field is optional.
     */
    private function pick(string $label, array $choices, bool $required, string $hint = ''): mixed
    {
        if (! $required) {
            $choices = ['' => '(skip)'] + $choices;
        }

        $picked = select($label, $choices, hint: $hint);

        return $picked === '' ? null : $picked;
    }

    /**
     * The resource a field draws its choices from: `from` (fixed) or `from_field`
     * (the singular value of another field, e.g. owner_type=site → sites).
     */
    private function fieldResource(array $meta, array $body): ?string
    {
        if (isset($meta['from'])) {
            return $meta['from'];
        }

        if (isset($meta['from_field']) && filled($body[$meta['from_field']] ?? null)) {
            return Str::plural($body[$meta['from_field']]);
        }

        return null;
    }

    /**
     * Build [id => label] choices from a resource's team-level list endpoint,
     * optionally narrowed to the ids already picked in another field.
     */
    private function resourceChoices(string $resource, string $team, array $meta, array $body): array
    {
        $routes = config("rocket_api.resources.{$resource}.routes", []);
        $path = $routes[''] ?? null;

        if ($path === null) {
            return [];
        }

        $items = ApiRequest::make()->paginated(str_replace('{team}', $team, is_array($path) ? $path['path'] : $path));

        $choices = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['id'])) {
                $choices[$item['id']] = $item['name'] ?? $item['slug'] ?? $item['id'];
            }
        }

        if ($only = $meta['only_selected'] ?? null) {
            $choices = array_intersect_key($choices, array_flip((array) ($body[$only] ?? [])));
        }

        return $choices;
    }

    private function fieldLabel(string $name): string
    {
        return ucfirst(str_replace(['_id', '_'], ['', ' '], $name));
    }

    protected function unknownActionMessage(array $writes, string $action): string
    {
        $available = implode(', ', array_keys($writes));

        return "'{$this->resource}' has no write action '{$action}'. Available: ".($available ?: 'none');
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

    protected function unsupportedScopeMessage(array $routes, ?string $subject = null): string
    {
        $hints = array_map(function (string $signature) {
            if ($signature === '') {
                return '  (team only)';
            }

            $parts = array_map(fn ($scope) => '--'.$scope.'=<value>', explode('|', $signature));

            return '  '.implode(' ', $parts);
        }, array_keys($routes));

        $subject ??= "'{$this->resource}'";

        return $subject.' doesn\'t support that combination of scopes. Available:'.PHP_EOL.implode(PHP_EOL, $hints);
    }
}
