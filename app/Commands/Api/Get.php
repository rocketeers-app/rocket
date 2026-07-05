<?php

namespace App\Commands\Api;

use App\Actions\ApiRequest;
use App\Commands\Api\Concerns\InteractsWithApi;
use App\Exceptions\StepException;
use Illuminate\Console\Command;

/**
 * Raw passthrough to any GET endpoint — the escape hatch for endpoints without a
 * dedicated command. The resolved team is prefixed automatically unless the path
 * is absolute (starts with "/", e.g. "/me").
 */
class Get extends Command
{
    use InteractsWithApi;

    protected $signature = 'get
        {path : API path, e.g. servers/{server}/stats or /me}
        {--team= : Team slug (defaults to your configured team)}
        {--page= : Fetch a single page instead of all}
        {--per-page= : Items per page (max 50)}
        {--metadata : Include results, meta and served_in for paginated responses}';

    protected $description = 'Call any GET endpoint directly';

    public function handle(): int
    {
        try {
            $path = $this->resolvePath();

            $perPage = (int) ($this->option('per-page') ?: 50);
            $page = $this->option('page') !== null ? (int) $this->option('page') : null;

            $result = ApiRequest::make()->paginated($path, (bool) $this->option('metadata'), $perPage, $page);
        } catch (StepException $e) {
            return $this->respondWithError($e->getMessage());
        }

        return $this->outputJson($result);
    }
}
