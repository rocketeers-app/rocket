<?php

namespace App\Commands\Api;

class Storages extends BaseApiCommand
{
    protected $signature = 'api:storages
        {team : Team slug}
        {--json : Output raw JSON}';

    protected $description = 'List storages in a team';

    protected function endpoint(string $team): string
    {
        return "{$team}/storages";
    }

    protected function resourceLabel(): string
    {
        return 'storages';
    }

    protected function tableHeaders(): array
    {
        return ['Name', 'Provider', 'Size', 'Created'];
    }

    protected function tableRow(array $item): array
    {
        return [
            $item['name'],
            $item['provider_slug'] ?? '-',
            $item['size'] ?? '-',
            $item['created_at'] ?? '',
        ];
    }
}
