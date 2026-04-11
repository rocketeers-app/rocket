<?php

namespace App\Commands\Api;

class Projects extends BaseApiCommand
{
    protected $signature = 'api:projects
        {team : Team slug}
        {--json : Output raw JSON}';

    protected $description = 'List projects in a team';

    protected function endpoint(string $team): string
    {
        return "{$team}/projects";
    }

    protected function resourceLabel(): string
    {
        return 'projects';
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
