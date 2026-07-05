<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class ApiRequest
{
    use AsAction;

    /**
     * Hard ceiling from the API (`per_page` maximum in the docs).
     */
    private const MAX_PER_PAGE = 50;

    public function handle(string $endpoint): array
    {
        return $this->get($endpoint)['data'] ?? $this->get($endpoint);
    }

    /**
     * A single GET returning the resource under `data`, or the raw body.
     */
    public function single(string $endpoint): array
    {
        $response = $this->get($endpoint);

        return $response['data'] ?? $response;
    }

    /**
     * Fetch a paginated endpoint. Walks every page by default; pass $page to
     * fetch just one. With $withMeta, wraps the items in a data/meta envelope.
     *
     * @return array{data: array, meta: array}|array
     */
    public function paginated(string $endpoint, bool $withMeta = false, int $perPage = self::MAX_PER_PAGE, ?int $page = null): array
    {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $items = [];
        $total = 0;
        $current = $page ?? 1;
        $start = microtime(true);
        $separator = str_contains($endpoint, '?') ? '&' : '?';

        do {
            $response = $this->get($endpoint.$separator.'per_page='.$perPage.'&page='.$current);

            if (isset($response['data'], $response['meta'])) {
                $items = array_merge($items, $response['data']);
                $total = $response['meta']['total'] ?? count($items);
                $perPage = $response['meta']['per_page'] ?? $perPage;
                $hasMore = $page === null && ($current + 1) <= ($response['meta']['last_page'] ?? 1);
                $current++;
            } else {
                $items = $response['data'] ?? $response;
                $hasMore = false;
            }
        } while ($hasMore);

        if ($withMeta) {
            return [
                'data' => $items,
                'results' => count($items),
                'meta' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
                ],
                'served_in' => round((microtime(true) - $start) * 1000, 2).'ms',
            ];
        }

        return $items;
    }

    /**
     * Single GET request returning the full decoded JSON response.
     */
    public function get(string $endpoint): array
    {
        $response = $this->client()->get($this->url($endpoint));

        $this->guard($response);

        return $response->json();
    }

    public function post(string $endpoint, array $body = []): array
    {
        return $this->send('post', $endpoint, $body);
    }

    public function put(string $endpoint, array $body = []): array
    {
        return $this->send('put', $endpoint, $body);
    }

    public function patch(string $endpoint, array $body = []): array
    {
        return $this->send('patch', $endpoint, $body);
    }

    public function delete(string $endpoint, array $body = []): array
    {
        return $this->send('delete', $endpoint, $body);
    }

    /**
     * Send a write request and return the decoded body ([] for 204/empty).
     */
    private function send(string $verb, string $endpoint, array $body): array
    {
        $response = $this->client()->asJson()->{$verb}($this->url($endpoint), $body);

        $this->guard($response);

        if ($response->status() === 204 || blank($response->body())) {
            return [];
        }

        return $response->json() ?? [];
    }

    /**
     * An authenticated, JSON pending request against the configured base URL.
     */
    private function client(): PendingRequest
    {
        $token = config('rocketeers.api_token');

        if (blank($token)) {
            throw new StepException('API token not configured. Run: rocket auth');
        }

        return Http::timeout(10)->withToken($token)->acceptJson();
    }

    private function url(string $endpoint): string
    {
        return rtrim(config('rocketeers.api_url'), '/').'/'.ltrim($endpoint, '/');
    }

    private function guard(\Illuminate\Http\Client\Response $response): void
    {
        if ($response->unauthorized()) {
            throw new StepException('Invalid or expired API token. Run: rocket auth');
        }

        if ($response->forbidden()) {
            throw new StepException('Token lacks the required ability for this endpoint.');
        }

        if ($response->status() === 422) {
            $errors = collect($response->json('errors', []))->flatten()->all();
            $detail = $errors
                ? PHP_EOL.'  • '.implode(PHP_EOL.'  • ', $errors)
                : ' '.($response->json('message') ?? 'invalid input');

            throw new StepException('Validation failed:'.$detail);
        }

        if ($response->failed()) {
            // Surface only the API's message, never the full body (which includes
            // a stack trace when the server runs with debug enabled).
            $message = $response->json('message') ?: Str::limit(strip_tags($response->body()), 200);

            throw new StepException('API request failed ('.$response->status().'): '.$message);
        }
    }
}
