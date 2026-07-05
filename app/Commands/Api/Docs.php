<?php

namespace App\Commands\Api;

use App\Commands\Api\Concerns\InteractsWithApi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

use function Laravel\Prompts\info;

/**
 * Generate a Markdown usage guide — one file per resource in docs/ — from the
 * endpoint registry, enriched with per-endpoint summaries pulled from the live
 * /docs endpoint (public, no auth). Dev-only: removed from the built PHAR via
 * config/commands.php so it never ships to end users.
 */
class Docs extends Command
{
    use InteractsWithApi;

    protected $signature = 'docs
        {--output= : Directory to write the guide to (default: docs/)}
        {--json : Output the raw command model as JSON instead of writing files}';

    protected $description = 'Generate a per-resource usage guide from the live API docs';

    /** Human-readable placeholder for each scope flag. */
    private const PLACEHOLDERS = [
        'id' => '<id>',
        'server' => '<server>',
        'environment' => '<environment>',
        'domain' => '<domain>',
    ];

    public function handle(): int
    {
        $resources = config('rocket_api.resources', []);
        $summaries = $this->summaries();

        if ($this->option('json')) {
            return $this->outputJson($this->model($resources));
        }

        $dir = rtrim($this->option('output') ?: base_path('docs'), '/');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        foreach ($resources as $key => $definition) {
            file_put_contents("{$dir}/{$key}.md", $this->entityMarkdown($key, $definition, $summaries));
        }

        file_put_contents("{$dir}/README.md", $this->indexMarkdown($resources));

        info('Wrote '.(count($resources) + 1).' files to '.$dir.'/');

        return self::SUCCESS;
    }

    /**
     * Fetch per-endpoint summaries from /docs, keyed by "METHOD normalizedPath".
     * Best-effort: returns [] if the docs can't be reached.
     *
     * @return array<string, string>
     */
    private function summaries(): array
    {
        try {
            $response = Http::timeout(10)->acceptJson()->get(rtrim(config('rocketeers.api_url'), '/').'/docs');

            if ($response->failed()) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        $map = [];

        foreach ($response->json('items', []) as $item) {
            $operations = $item['operations'] ?? [];

            foreach ($item['subitems'] ?? [] as $subitem) {
                $operations = array_merge($operations, $subitem['operations'] ?? []);
            }

            foreach ($operations as $operation) {
                $key = ($operation['method'] ?? 'GET').' '.$this->normalize($operation['path']);
                $map[$key] = $operation['summary'] ?? '';
            }
        }

        return $map;
    }

    private function entityMarkdown(string $key, array $definition, array $summaries): string
    {
        $lines = ['# '.ucfirst($key), ''];

        if ($description = $definition['description'] ?? null) {
            $lines[] = $description.'.';
            $lines[] = '';
        }

        $lines[] = '```bash';

        foreach ($definition['routes'] ?? [] as $signature => $route) {
            $path = is_array($route) ? $route['path'] : $route;
            $lines[] = $this->example("rocket {$key}", $signature, $summaries['GET '.$this->normalize($path)] ?? null);
        }

        foreach ($definition['writes'] ?? [] as $action => $spec) {
            $lines[] = $this->writeExample($key, $action, $spec, $summaries);
        }

        $lines[] = '```';
        $lines[] = '';
        $lines[] = '[← All commands](README.md)';
        $lines[] = '';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function indexMarkdown(array $resources): string
    {
        $base = config('rocketeers.api_url');

        $lines = [
            '# Rocket API commands',
            '',
            "Generated from the live API docs at `{$base}/docs`. Run `rocket docs` to refresh.",
            '',
            '## Getting started',
            '',
            '```bash',
            'rocket auth                    # store your API token, set a default team',
            'rocket url <base-url>          # point the CLI at your API',
            'rocket use-team <team-slug>    # set a default team (or pass --team= each call)',
            'rocket check                   # verify every documented endpoint is reachable',
            '```',
            '',
            'Every resource command is team-scoped: the team comes from `--team=<slug>` or your',
            'configured default. Reads print a table (or `--json`); writes use `--create`,',
            '`--update`, `--delete`, or `--action=<name>` with `-F key=value` body fields.',
            '',
            '| Global flag | Description |',
            '|-------------|-------------|',
            '| `--team=` | Team slug (falls back to your default) |',
            '| `--json` | Output raw JSON |',
            '| `--id= / --server= / --environment= / --domain=` | Scope selectors |',
            '| `-F, --field key=value` | Body field for writes (repeatable) |',
            '| `--data \'<json>\'` | Raw JSON body for writes |',
            '| `--force` | Skip the confirmation on destructive actions |',
            '',
            '## Resources',
            '',
        ];

        foreach (array_keys($resources) as $key) {
            $lines[] = "- [{$key}]({$key}.md)";
        }

        $lines = array_merge($lines, [
            '',
            '## Raw requests',
            '',
            'Any endpoint is reachable directly, with the team prefixed automatically',
            '(absolute `/…` paths are left as-is):',
            '',
            '```bash',
            'rocket get servers/<id>/stats --team=<team>',
            'rocket post servers/<id>/reboot --team=<team>',
            'rocket put sites/<id> --team=<team> -F name=Renamed',
            'rocket delete sites/<id> --team=<team>   # confirms, or --force',
            '```',
            '',
        ]);

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function example(string $command, string $signature, ?string $summary): string
    {
        $flags = array_merge(['--team=<team>'], $this->scopeFlags($signature));
        $line = $command.' '.implode(' ', $flags);

        return $summary ? str_pad($line, 52).' # '.$summary : $line;
    }

    private function writeExample(string $key, string $action, array $spec, array $summaries): string
    {
        // Pick the simplest route (fewest scopes) for the example.
        $signature = collect(array_keys($spec['routes']))->sortBy(fn ($s) => strlen($s))->first();

        $flags = array_merge([$this->actionFlag($action), '--team=<team>'], $this->scopeFlags($signature));

        foreach ($spec['fields'] ?? [] as $field => $meta) {
            if ($meta['required'] ?? false) {
                $flags[] = "-F {$field}=<{$field}>";
            }
        }

        $path = $spec['routes'][$signature];
        $summary = $summaries[$spec['method'].' '.$this->normalize($path)] ?? null;
        $note = ($spec['confirm'] ?? false) ? 'confirms; --force to skip' : $summary;

        $line = "rocket {$key} ".implode(' ', $flags);

        return $note ? str_pad($line, 52).' # '.$note : $line;
    }

    private function actionFlag(string $action): string
    {
        return match ($action) {
            'create' => '--create',
            'update' => '--update',
            'delete' => '--delete',
            default => "--action={$action}",
        };
    }

    private function scopeFlags(string $signature): array
    {
        if ($signature === '') {
            return [];
        }

        return array_map(
            fn ($scope) => '--'.$scope.'='.(self::PLACEHOLDERS[$scope] ?? '<value>'),
            explode('|', $signature)
        );
    }

    private function normalize(string $path): string
    {
        return preg_replace('/\{[^}]+\}/', '{}', ltrim($path, '/'));
    }

    /**
     * Machine-readable command model (for --json).
     */
    private function model(array $resources): array
    {
        return collect($resources)->map(fn ($definition, $key) => [
            'command' => $key,
            'description' => $definition['description'] ?? null,
            'reads' => array_keys($definition['routes'] ?? []),
            'writes' => array_keys($definition['writes'] ?? []),
        ])->values()->all();
    }
}
