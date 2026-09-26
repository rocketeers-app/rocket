<?php

namespace App\Actions;

use App\Api\Requests\GetApiDocs;
use App\Exceptions\StepException;
use App\Schema\SchemaCache;
use App\Schema\SchemaCompactor;
use Lorisleiva\Actions\Concerns\AsAction;

class RefreshSchema
{
    use AsAction;

    /** @return array{version: ?string, operations: int, changed: bool} */
    public function handle(bool $force = false): array
    {
        $cache = app(SchemaCache::class);
        $etag = $force ? null : $cache->etag();

        $response = (new SendApiRequest)(new GetApiDocs($etag), allowFailure: true, requiresToken: false);

        if ($response->status() === 304) {
            $cache->touch();

            return ['version' => $cache->version(), 'operations' => count($cache->operations()), 'changed' => false];
        }

        $document = $response->successful() ? $response->json() : null;

        if (! is_array($document) || ! isset($document['items'])) {
            throw new StepException("Could not read the API schema (HTTP {$response->status()}).");
        }

        $compact = (new SchemaCompactor)->handle($document);
        $cache->store($compact, $response->header('ETag') ?: null);

        return ['version' => $compact['version'], 'operations' => count($compact['operations']), 'changed' => true];
    }
}
