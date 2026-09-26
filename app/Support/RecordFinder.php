<?php

namespace App\Support;

use App\Actions\SendApiRequest;
use App\Api\ApiErrorPresenter;
use App\Api\Requests\CallOperation;
use App\Api\RocketeersConnector;
use App\Schema\Operation;
use Saloon\Exceptions\Request\FatalRequestException;

/**
 * Reads records from a list operation. Asks the server to search, and filters again locally,
 * because not every list understands `search`.
 */
class RecordFinder
{
    public const int PER_PAGE = 50;

    private const int MAX_PAGES = 10;

    /**
     * @param  array<string, string>  $pathValues
     * @return array{records: array<int, array<string, mixed>>, total: int}
     */
    public function search(Operation $list, array $pathValues, string $query = ''): array
    {
        $response = (new SendApiRequest)(CallOperation::for($list, $pathValues, [
            'search' => $query === '' ? null : $query,
            'per_page' => self::PER_PAGE,
        ]));

        $records = $this->records($response->json('data'));

        return [
            'records' => array_values(array_filter($records, fn (array $record): bool => Records::contains($record, $query))),
            'total' => (int) ($response->json('meta.total') ?? $response->json('total') ?? count($records)),
        ];
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

    /** @return array<int, array<string, mixed>> */
    private function records(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter(array_is_list($data) ? $data : [], fn (mixed $record): bool => is_array($record)));
    }
}
