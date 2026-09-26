<?php

namespace App\Api;

use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\HasPagination;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

/** The Rocketeers API, authenticated with the saved Rocket CLI token unless another is passed in. */
class RocketeersConnector extends Connector implements HasPagination
{
    use AcceptsJson;
    use HasTimeout;

    protected int $connectTimeout = 10;

    protected int $requestTimeout = 60;

    public function __construct(private readonly ?string $token = null) {}

    public function resolveBaseUrl(): string
    {
        return rtrim((string) config('rocketeers.api_url'), '/');
    }

    public function paginate(Request $request): ListPaginator
    {
        return new ListPaginator($this, $request);
    }

    protected function defaultAuth(): ?Authenticator
    {
        $token = $this->token ?? config('rocketeers.api_token');

        return blank($token) ? null : new TokenAuthenticator((string) $token);
    }

    protected function defaultHeaders(): array
    {
        return [
            'User-Agent' => 'rocket-cli/'.config('app.version'),
        ];
    }

    protected function defaultConfig(): array
    {
        return [
            'verify' => true,
        ];
    }
}
