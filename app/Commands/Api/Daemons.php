<?php

namespace App\Commands\Api;

class Daemons extends BaseApiCommand
{
    protected $signature = 'api:daemons
        {team : Team slug}
        {--json : Output raw JSON}';

    protected $description = 'List daemons in a team';

    protected function endpoint(string $team): string
    {
        return "{$team}/daemons";
    }

    protected function resourceLabel(): string
    {
        return 'daemons';
    }

    protected function tableHeaders(): array
    {
        return ['Name', 'Command', 'User', 'Processes'];
    }

    protected function tableRow(array $item): array
    {
        return [
            $item['name'],
            $item['command'],
            $item['username'] ?? '-',
            $item['processes'] ?? 1,
        ];
    }
}
