<?php

namespace App\Support;

use App\Actions\SendApiRequest;
use App\Api\ApiErrorPresenter;
use App\Api\Requests\CallOperation;
use App\Api\RocketeersConnector;
use App\Schema\Operation;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Response;

/**
 * Reads records from a list operation. Asks the server to search, and filters again locally,
 * because not every list understands `search`.
 */
class RecordFinder
{
    public const int PER_PAGE = 50;

    private const int MAX_PAGES = 10;

    private const int CONCURRENCY = 8;

    /**
     * @param  array<string, string>  $pathValues
     * @return array{records: array<int, array<string, mixed>>, total: int, complete: bool}
     */
    public function search(Operation $list, array $pathValues, string $query = ''): array
    {
        return $this->result((new SendApiRequest)($this->searchRequest($list, $pathValues, $query)), $query);
    }

    public function searchEach(Operation $list, array $pathValuesByKey, string $query = ''): array
    {
        $responses = [];
        $failure = null;

        (new RocketeersConnector)->pool(
            requests: array_map(fn (array $pathValues): CallOperation => $this->searchRequest($list, $pathValues, $query), $pathValuesByKey),
            concurrency: self::CONCURRENCY,
            responseHandler: function (Response $response, int|string $key) use (&$responses): void {
                $responses[$key] = $response;
            },
            exceptionHandler: function (mixed $reason) use (&$failure): void {
                $failure ??= $reason;
            },
        )->send()->wait();

        if ($failure instanceof RequestException) {
            throw ApiErrorPresenter::fromResponse($failure->getResponse());
        }

        if ($failure !== null) {
            throw ApiErrorPresenter::unreachable();
        }

        $failed = collect($responses)->first(fn (Response $response): bool => $response->failed());

        if ($failed instanceof Response) {
            throw ApiErrorPresenter::fromResponse($failed);
        }

        $results = [];

        foreach (array_keys($pathValuesByKey) as $key) {
            $results[$key] = $this->result($responses[$key], $query);
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $pathValues
     * @return array<int, array<string, mixed>>
     */
    public function all(Operation $list, array $pathValues): array
    {
        try {
            return collect((new RocketeersConnector)
                ->paginate(CallOperation::for($list, $pathValues))
                ->setPerPageLimit(self::PER_PAGE)
                ->setMaxPages(self::MAX_PAGES)
                ->items())
                ->filter(fn (mixed $record): bool => is_array($record))
                ->values()
                ->all();
        } catch (FatalRequestException) {
            throw ApiErrorPresenter::unreachable();
        }
    }

    private function searchRequest(Operation $list, array $pathValues, string $query): CallOperation
    {
        return CallOperation::for($list, $pathValues, [
            'search' => $query === '' ? null : $query,
            'per_page' => self::PER_PAGE,
        ]);
    }

    private function result(Response $response, string $query): array
    {
        $records = $this->records($response->json('data'));
        $total = (int) ($response->json('meta.total') ?? $response->json('total') ?? count($records));

        return [
            'records' => array_values(array_filter($records, fn (array $record): bool => Records::contains($record, $query))),
            'total' => $total,
            'complete' => $total <= count($records),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function records(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter(array_is_list($data) ? $data : [], fn (mixed $record): bool => is_array($record)));
    }
}
