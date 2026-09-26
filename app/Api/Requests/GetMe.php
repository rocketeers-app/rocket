<?php

namespace App\Api\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetMe extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/me';
    }
}
