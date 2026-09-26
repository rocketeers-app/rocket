<?php

namespace App\Api\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetMyTeams extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/me/teams';
    }
}
