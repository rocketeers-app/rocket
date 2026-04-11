<?php

namespace App\Commands\Api;

class Sites extends BaseApiCommand
{
    protected $signature = 'api:sites
        {team : Team slug}
        {--json : Output raw JSON}';

    protected $description = 'List sites in a team';

    protected function endpoint(string $team): string
    {
        return "{$team}/sites";
    }

    protected function resourceLabel(): string
    {
        return 'sites';
    }

    protected function tableHeaders(): array
    {
        return ['Name', 'Created'];
    }

    protected function tableRow(array $item): array
    {
        return [
            $item['name'],
            $item['created_at'] ?? '',
        ];
    }
}
