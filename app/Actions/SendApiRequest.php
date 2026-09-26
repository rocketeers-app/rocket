<?php

namespace App\Actions;

use App\Api\ApiErrorPresenter;
use App\Api\RocketeersConnector;
use Lorisleiva\Actions\Concerns\AsAction;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Request;
use Saloon\Http\Response;

class SendApiRequest
{
    use AsAction;

    public function handle(Request $request, ?string $token = null, ?string $teamName = null, bool $allowFailure = false, bool $requiresToken = true): Response
    {
        $token ??= config('rocketeers.api_token');

        if (blank($token) && $requiresToken) {
            throw ApiErrorPresenter::missingToken();
        }

        try {
            $response = (new RocketeersConnector(blank($token) ? null : (string) $token))->send($request);
        } catch (FatalRequestException) {
            throw ApiErrorPresenter::unreachable();
        }

        if ($response->failed() && ! $allowFailure) {
            throw ApiErrorPresenter::fromResponse($response, $teamName);
        }

        return $response;
    }
}
