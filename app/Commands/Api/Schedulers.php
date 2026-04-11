<?php

namespace App\Commands\Api;

class Schedulers extends BaseApiCommand
{
    protected $signature = 'api:schedulers
        {team : Team slug}
        {--json : Output raw JSON}';

    protected $description = 'List schedulers in a team';

    protected function endpoint(string $team): string
    {
        return "{$team}/schedulers";
    }

    protected function resourceLabel(): string
    {
        return 'schedulers';
    }

    protected function tableHeaders(): array
    {
        return ['Name', 'Command', 'Frequency', 'Last Heartbeat'];
    }

    protected function tableRow(array $item): array
    {
        return [
            $item['name'],
            $item['command'],
            $item['frequency_name'] ?? $item['frequency'] ?? '-',
            $item['heartbeated_at'] ?? 'Never',
        ];
    }
}
