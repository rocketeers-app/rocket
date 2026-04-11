<?php

namespace App\Commands\Api;

class Repositories extends BaseApiCommand
{
    protected $signature = 'api:repositories
        {team : Team slug}
        {--json : Output raw JSON}';

    protected $description = 'List repositories in a team';

    protected function endpoint(string $team): string
    {
        return "{$team}/repositories";
    }

    protected function resourceLabel(): string
    {
        return 'repositories';
    }

    protected function tableHeaders(): array
    {
        return ['Name', 'Path', 'Language', 'Private'];
    }

    protected function tableRow(array $item): array
    {
        return [
            $item['name'],
            $item['path'] ?? '-',
            $item['language'] ?? '-',
            $item['is_private'] ? 'Yes' : 'No',
        ];
    }
}
