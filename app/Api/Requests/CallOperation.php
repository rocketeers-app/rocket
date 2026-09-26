<?php

namespace App\Api\Requests;

use App\Schema\Operation;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;

/** Any operation from the API schema, with its path placeholders filled in. */
class CallOperation extends Request implements Paginatable
{
    /**
     * @param  array<string, string>  $pathValues
     * @param  array<string, mixed>  $queryValues
     */
    public function __construct(
        public readonly Operation $operation,
        public readonly array $pathValues,
        public readonly array $queryValues = [],
    ) {
        $this->method = Method::from($operation->method);
    }

    /**
     * @param  array<string, string>  $pathValues
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     */
    public static function for(Operation $operation, array $pathValues, array $query = [], array $body = []): self
    {
        if ($body === [] && in_array($operation->method, ['GET', 'DELETE'], true)) {
            return new self($operation, $pathValues, $query);
        }

        return new CallOperationWithBody($operation, $pathValues, $query, $body);
    }

    public function resolveEndpoint(): string
    {
        return preg_replace_callback(
            '/\{([^}]+)\}/',
            fn (array $matches): string => rawurlencode((string) ($this->pathValues[$matches[1]] ?? $matches[0])),
            $this->operation->path,
        );
    }

    protected function defaultQuery(): array
    {
        return array_filter($this->queryValues, fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
