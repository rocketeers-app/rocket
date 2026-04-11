<?php

namespace App\Commands\Api;

class Errors extends BaseApiCommand
{
    protected $signature = 'api:errors
        {team : Team slug}
        {--json : Output raw JSON}';

    protected $description = 'List errors in a team';

    protected function endpoint(string $team): string
    {
        return "{$team}/errors";
    }

    protected function resourceLabel(): string
    {
        return 'errors';
    }

    protected function tableHeaders(): array
    {
        return ['Message', 'Class', 'File', 'Line', 'Count', 'Last Seen'];
    }

    protected function tableRow(array $item): array
    {
        return [
            str($item['message'] ?? '')->limit(50)->toString(),
            $item['class'] ?? '-',
            $item['file'] ?? '-',
            $item['line'] ?? '-',
            $item['occurrences'] ?? 0,
            $item['last_occurred_at'] ?? '-',
        ];
    }
}
