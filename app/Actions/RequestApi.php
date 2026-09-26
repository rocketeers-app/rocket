<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsAction;

class RequestApi
{
    use AsAction;

    public function handle(string $path, ?string $token = null): Response
    {
        $baseUrl = rtrim((string) config('rocketeers.api_url'), '/');
        $token ??= (string) config('rocketeers.api_token');

        if (blank($token)) {
            throw new StepException('No Rocketeers API token configured. Run `rocket login` first.');
        }

        try {
            $response = Http::baseUrl($baseUrl)
                ->withToken($token)
                ->acceptJson()
                ->timeout(10)
                ->get(ltrim($path, '/'));
        } catch (ConnectionException) {
            throw new StepException("Could not reach Rocketeers at {$baseUrl}.");
        }

        if ($response->status() === 401) {
            throw new StepException('This API token is not valid. Create a new one in Rocketeers under Settings, API, and run `rocket login`.');
        }

        if ($response->failed()) {
            throw new StepException($response->json('message') ?: "Rocketeers answered with HTTP {$response->status()}.");
        }

        return $response;
    }
}
