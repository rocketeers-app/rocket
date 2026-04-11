<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsAction;

class ApiRequest
{
    use AsAction;

    public function handle(string $endpoint, bool $raw = false): array
    {
        $token = config('rocketeers.api_token');

        if (blank($token)) {
            throw new StepException('API token not configured. Run: rocket api:auth');
        }

        $baseUrl = rtrim(config('rocketeers.api_url'), '/');

        $response = Http::timeout(10)
            ->withToken($token)
            ->acceptJson()
            ->get($baseUrl.'/'.ltrim($endpoint, '/'));

        if ($response->unauthorized()) {
            throw new StepException('Invalid or expired API token. Run: rocket api:auth');
        }

        if ($response->forbidden()) {
            throw new StepException('Token lacks the required ability for this endpoint.');
        }

        if ($response->failed()) {
            throw new StepException('API request failed: '.$response->status().' '.$response->body());
        }

        if ($raw) {
            return $response->json();
        }

        return $response->json('data') ?? $response->json();
    }
}
