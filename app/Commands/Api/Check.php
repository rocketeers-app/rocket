<?php

namespace App\Commands\Api;

use App\Commands\Api\Concerns\InteractsWithApi;
use App\Exceptions\StepException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Diff the local endpoint registry against the live /docs schema so coverage
 * gaps are visible. Every GET endpoint in the docs should be reachable by a
 * dedicated command or listed under `raw_only` in config/rocket_api.php.
 */
class Check extends Command
{
    use InteractsWithApi;

    protected $signature = 'check {--json : Output raw JSON}';

    protected $description = 'Check the endpoint registry against the live API docs';

    public function handle(): int
    {
        try {
            $docs = $this->fetchDocs();
        } catch (StepException $e) {
            return $this->respondWithError($e->getMessage());
        }

        $docPaths = $this->docGetPaths($docs);        // normalized => original
        [$byCommand, $rawOnly] = $this->registeredPaths();

        $covered = [];
        $missing = [];

        foreach ($docPaths as $normalized => $original) {
            if (isset($byCommand[$normalized])) {
                $covered[] = ['path' => $original, 'via' => $byCommand[$normalized]];
            } elseif (in_array($normalized, $rawOnly, true)) {
                $covered[] = ['path' => $original, 'via' => 'get (raw)'];
            } else {
                $missing[] = $original;
            }
        }

        $stale = [];
        foreach ($byCommand as $normalized => $command) {
            if (! isset($docPaths[$normalized])) {
                $stale[] = ['path' => $normalized, 'command' => $command];
            }
        }

        if ($this->option('json')) {
            $this->outputJson([
                'total' => count($docPaths),
                'covered' => count($covered),
                'missing' => $missing,
                'stale' => $stale,
            ]);
        } else {
            $this->report($docPaths, $covered, $missing, $stale);
        }

        return $missing ? self::FAILURE : self::SUCCESS;
    }

    private function fetchDocs(): array
    {
        $response = Http::timeout(10)->acceptJson()->get(rtrim(config('rocketeers.api_url'), '/').'/docs');

        if ($response->failed()) {
            throw new StepException('Could not fetch API docs ('.$response->status().') from '.config('rocketeers.api_url').'/docs');
        }

        return $response->json();
    }

    /**
     * @return array<string, string> normalized path => original doc path
     */
    private function docGetPaths(array $docs): array
    {
        $paths = [];

        foreach ($docs['items'] ?? [] as $item) {
            foreach ($this->operationsOf($item) as $operation) {
                if (($operation['method'] ?? null) === 'GET') {
                    $paths[$this->normalize($operation['path'])] = $operation['path'];
                }
            }
        }

        ksort($paths);

        return $paths;
    }

    private function operationsOf(array $item): array
    {
        $operations = $item['operations'] ?? [];

        foreach ($item['subitems'] ?? [] as $subitem) {
            $operations = array_merge($operations, $subitem['operations'] ?? []);
        }

        return $operations;
    }

    /**
     * @return array{0: array<string, string>, 1: string[]} [normalized => command key], [raw-only normalized]
     */
    private function registeredPaths(): array
    {
        $byCommand = [];

        foreach (config('rocket_api.resources', []) as $key => $definition) {
            foreach ($definition['routes'] ?? [] as $route) {
                $path = is_array($route) ? $route['path'] : $route;
                $byCommand[$this->normalize($path)] = $key;
            }
        }

        $rawOnly = array_map(fn ($path) => $this->normalize($path), config('rocket_api.raw_only', []));

        return [$byCommand, $rawOnly];
    }

    /**
     * Reduce a path to a comparable shape: no leading slash, placeholder names erased.
     */
    private function normalize(string $path): string
    {
        return preg_replace('/\{[^}]+\}/', '{}', ltrim($path, '/'));
    }

    private function report(array $docPaths, array $covered, array $missing, array $stale): void
    {
        $this->newLine();
        $this->info(sprintf('%d/%d GET endpoints covered.', count($covered), count($docPaths)));

        if ($missing) {
            $this->newLine();
            $this->error('Missing (no command, not raw-only):');
            $this->table(['Endpoint'], array_map(fn ($path) => [$path], $missing));
        }

        if ($stale) {
            $this->newLine();
            $this->warn('Stale (in registry, not in docs):');
            $this->table(['Registry path', 'Command'], array_map(fn ($row) => [$row['path'], $row['command']], $stale));
        }

        if (! $missing && ! $stale) {
            $this->newLine();
            $this->info('Registry is in sync with the API docs.');
        }
    }
}
