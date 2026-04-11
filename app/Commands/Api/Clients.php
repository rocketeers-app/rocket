<?php

namespace App\Commands\Api;

class Clients extends BaseApiCommand
{
    protected $signature = 'api:clients
        {team : Team slug}
        {--json : Output raw JSON}';

    protected $description = 'List clients in a team';

    protected function endpoint(string $team): string
    {
        return "{$team}/clients";
    }

    protected function resourceLabel(): string
    {
        return 'clients';
    }

    protected function tableHeaders(): array
    {
        return ['Name', 'Slug', 'Created'];
    }

    protected function tableRow(array $item): array
    {
        return [
            $item['name'],
            $item['slug'],
            $item['created_at'] ?? '',
        ];
    }
}
