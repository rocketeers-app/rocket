<?php

namespace App\Commands\Api;

class Servers extends BaseApiCommand
{
    protected $signature = 'api:servers
        {team : Team slug}
        {--json : Output raw JSON}';

    protected $description = 'List servers in a team';

    protected function endpoint(string $team): string
    {
        return "{$team}/servers";
    }

    protected function resourceLabel(): string
    {
        return 'servers';
    }

    protected function tableHeaders(): array
    {
        return ['Name', 'IP', 'Provider', 'Type', 'Status', 'Price'];
    }

    protected function tableRow(array $item): array
    {
        return [
            $item['name'],
            $item['ip'] ?? '-',
            $item['provider_slug'] ?? '-',
            $item['server_type'] ?? '-',
            $item['status'] ?? '-',
            $item['price'] ? '€'.number_format($item['price'], 2) : '-',
        ];
    }
}
