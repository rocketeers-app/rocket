<?php

namespace App\Api;

use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\PagedPaginator;

/**
 * Walks a list operation page by page. Lists answer in two shapes: a resource collection with its
 * pagination under `meta`, or a bare Laravel paginator with it at the top level and an empty `meta`.
 */
class ListPaginator extends PagedPaginator
{
    protected ?int $perPageLimit = 50;

    public static function lastPage(Response $response): int
    {
        return (int) ($response->json('meta.last_page') ?? $response->json('last_page') ?? 1);
    }

    public static function currentPage(Response $response): int
    {
        return (int) ($response->json('meta.current_page') ?? $response->json('current_page') ?? 1);
    }

    protected function isLastPage(Response $response): bool
    {
        return self::currentPage($response) >= self::lastPage($response);
    }

    protected function getPageItems(Response $response, Request $request): array
    {
        if ($response->failed()) {
            throw ApiErrorPresenter::fromResponse($response);
        }

        $items = $response->json('data');

        return is_array($items) && array_is_list($items) ? $items : [];
    }

    protected function getTotalPages(Response $response): int
    {
        return self::lastPage($response);
    }
}
