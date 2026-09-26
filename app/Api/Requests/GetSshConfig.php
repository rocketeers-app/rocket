<?php

namespace App\Api\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetSshConfig extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/ssh/config';
    }
}
