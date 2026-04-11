<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsAction;

class ApiRequest
{
    use AsAction;

    public function handle(string $endpoint): array
    {
        return $this->get($endpoint)['data'] ?? $this->get($endpoint);
    }

    /**
     * Fetch all pages of a paginated endpoint.
     *
     * @return array{data: array, meta: array} When withMeta is true
     * @return array When withMeta is false (flat data array)
     */
    public function paginated(string $endpoint, bool $withMeta = false): array
    {
        $items = [];
        $page = 1;
        $meta = [];
        $servedIn = [];

        do {
            $separator = str_contains($endpoint, '?') ? '&' : '?';
            $response = $this->get($endpoint.$separator.'per_page=50&page='.$page);

            if (isset($response['served_in'])) {
                $servedIn[] = $response['served_in'];
            }

            if (isset($response['data']) && isset($response['meta'])) {
                $items = array_merge($items, $response['data']);
                $meta = $response['meta'];
                $page++;
                $hasMore = $page <= ($meta['last_page'] ?? 1);
            } else {
                $items = $response['data'] ?? $response;
                $hasMore = false;
            }
        } while ($hasMore);

        if ($withMeta) {
            return [
                'data' => $items,
                'results' => count($items),
                'meta' => $meta,
                'served_in' => $servedIn,
            ];
        }

        return $items;
    }

    /**
     * Single GET request returning the full JSON response.
     */
    public function get(string $endpoint): array
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

        return $response->json();
    }
}
