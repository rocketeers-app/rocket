<?php

namespace App\Api\Requests;

use App\Schema\Operation;
use Saloon\Contracts\Body\HasBody;
use Saloon\Traits\Body\HasJsonBody;

class CallOperationWithBody extends CallOperation implements HasBody
{
    use HasJsonBody;

    /**
     * @param  array<string, string>  $pathValues
     * @param  array<string, mixed>  $queryValues
     * @param  array<string, mixed>  $payload
     */
    public function __construct(Operation $operation, array $pathValues, array $queryValues, public readonly array $payload)
    {
        parent::__construct($operation, $pathValues, $queryValues);
    }

    protected function defaultBody(): array
    {
        return $this->payload;
    }
}
