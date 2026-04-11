<?php

namespace App\Commands\Api;

class Domains extends BaseApiCommand
{
    protected $signature = 'api:domains
        {team : Team slug}
        {--json : Output raw JSON}';

    protected $description = 'List domains in a team';

    protected function endpoint(string $team): string
    {
        return "{$team}/domains";
    }

    protected function resourceLabel(): string
    {
        return 'domains';
    }

    protected function tableHeaders(): array
    {
        return ['Name', 'FQDN', 'WWW', 'HTTPS', 'Expires'];
    }

    protected function tableRow(array $item): array
    {
        return [
            $item['name'],
            $item['fqdn'] ?? '-',
            $item['use_www'] ? 'Yes' : 'No',
            $item['https_redirect'] ? 'Yes' : 'No',
            $item['expires_at'] ?? '-',
        ];
    }
}
