<?php

namespace App\Commands\Api;

class Tasks extends BaseApiCommand
{
    protected $signature = 'api:tasks
        {team : Team slug}
        {--json : Output raw JSON}';

    protected $description = 'List server tasks in a team';

    protected function endpoint(string $team): string
    {
        return "{$team}/tasks";
    }

    protected function resourceLabel(): string
    {
        return 'tasks';
    }

    protected function tableHeaders(): array
    {
        return ['Name', 'Status', 'Deploys', 'Created'];
    }

    protected function tableRow(array $item): array
    {
        return [
            $item['name'],
            $item['status'] ?? '-',
            $item['deploys'] ? 'Yes' : 'No',
            $item['created_at'] ?? '',
        ];
    }
}
