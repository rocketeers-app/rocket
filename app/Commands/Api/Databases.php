<?php

namespace App\Commands\Api;

class Databases extends BaseApiCommand
{
    protected $signature = 'api:databases
        {team : Team slug}
        {--json : Output raw JSON}';

    protected $description = 'List databases in a team';

    protected function endpoint(string $team): string
    {
        return "{$team}/databases";
    }

    protected function resourceLabel(): string
    {
        return 'databases';
    }

    protected function tableHeaders(): array
    {
        return ['Name', 'Type', 'Kind', 'Replicas', 'Created'];
    }

    protected function tableRow(array $item): array
    {
        return [
            $item['name'],
            $item['type'] ?? '-',
            $item['kind'] ?? '-',
            $item['replicas'] ?? 0,
            $item['created_at'] ?? '',
        ];
    }
}
