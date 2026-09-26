<?php

namespace App\Api\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetApiDocs extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly ?string $etag = null) {}

    public function resolveEndpoint(): string
    {
        return '/docs';
    }

    protected function defaultHeaders(): array
    {
        return $this->etag === null ? [] : ['If-None-Match' => $this->etag];
    }
}
