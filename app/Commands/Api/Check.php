<?php

namespace App\Commands\Api;

use App\Commands\Api\Concerns\InteractsWithApi;
use App\Exceptions\StepException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Diff the local endpoint registry against the live /docs schema so coverage
 * gaps are visible. Every GET endpoint must have a dedicated command or be in
 * `raw_only`; writes report whether they have a dedicated action or are reachable
 * only via the generic verb commands (get/post/put/patch/delete).
 */
class Check extends Command
{
    use InteractsWithApi;

    protected $signature = 'check {--json : Output raw JSON}';

    protected $description = 'Check the command registry against the live API docs';

    public function handle(): int
    {
        try {
            $docs = $this->fetchDocs();
        } catch (StepException $e) {
            return $this->respondWithError($e->getMessage());
        }

        $dedicated = $this->dedicatedOperations();
        $rawOnly = array_flip(array_map(fn ($p) => $this->normalize($p), config('rocket_api.raw_only', [])));

        $covered = 0;
        $missing = [];        // GET endpoints with no command and not raw_only
        $genericWrites = [];  // writes reachable via generic verb only

        foreach ($this->docOperations($docs) as $op) {
            $norm = $this->normalize($op['path']);

            if (isset($dedicated[$op['method'].' '.$norm]) || isset($rawOnly[$norm])) {
                $covered++;
            } elseif ($op['method'] === 'GET') {
                $missing[] = $op['path'];
            } else {
                $genericWrites[] = $op['method'].' '.$op['path'];
            }
        }

        if ($this->option('json')) {
            $this->outputJson([
                'covered' => $covered,
                'missing' => $missing,
                'generic_only_writes' => $genericWrites,
            ]);
        } else {
            $this->report($covered, $missing, $genericWrites);
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
     * @return array<int, array{method: string, path: string}>
     */
    private function docOperations(array $docs): array
    {
        $ops = [];

        foreach ($docs['items'] ?? [] as $item) {
            foreach ($this->operationsOf($item) as $operation) {
                $ops[] = ['method' => $operation['method'] ?? 'GET', 'path' => $operation['path']];
            }
        }

        return $ops;
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
     * Every "METHOD normalizedPath" the registry gives a dedicated command:
     * GET reads plus every write action route.
     *
     * @return array<string, true>
     */
    private function dedicatedOperations(): array
    {
        $set = [];

        foreach (config('rocket_api.resources', []) as $definition) {
            foreach ($definition['routes'] ?? [] as $route) {
                $path = is_array($route) ? $route['path'] : $route;
                $set['GET '.$this->normalize($path)] = true;
            }

            foreach ($definition['writes'] ?? [] as $spec) {
                foreach ($spec['routes'] as $path) {
                    $set[$spec['method'].' '.$this->normalize($path)] = true;
                }
            }
        }

        return $set;
    }

    /**
     * Reduce a path to a comparable shape: no leading slash, placeholder names erased.
     */
    private function normalize(string $path): string
    {
        return preg_replace('/\{[^}]+\}/', '{}', ltrim($path, '/'));
    }

    private function report(int $covered, array $missing, array $genericWrites): void
    {
        info($covered.' endpoints have a dedicated command or raw fallback.');

        if ($missing) {
            error('Missing GET endpoints (no command, not raw_only):');
            table(['Endpoint'], array_map(fn ($p) => [$p], $missing));
        }

        if ($genericWrites) {
            warning('Writes reachable via generic verb only (no dedicated action):');
            table(['Endpoint'], array_map(fn ($p) => [$p], $genericWrites));
        }

        if (! $missing && ! $genericWrites) {
            info('Every documented endpoint has a dedicated command.');
        }
    }
}
