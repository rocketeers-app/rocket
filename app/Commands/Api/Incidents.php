<?php

namespace App\Commands\Api;

class Incidents extends BaseApiCommand
{
    protected $signature = 'api:incidents
        {team : Team slug}
        {--json : Output raw JSON}';

    protected $description = 'List incidents in a team';

    protected function endpoint(string $team): string
    {
        return "{$team}/incidents";
    }

    protected function resourceLabel(): string
    {
        return 'incidents';
    }

    protected function tableHeaders(): array
    {
        return ['ID', 'Status', 'Severity', 'Resolved', 'Created'];
    }

    protected function tableRow(array $item): array
    {
        return [
            $item['id'],
            $item['status'] ?? '-',
            $item['severity'] ?? '-',
            $item['resolved_at'] ?? '-',
            $item['created_at'] ?? '',
        ];
    }
}
