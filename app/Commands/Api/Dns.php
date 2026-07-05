<?php

namespace App\Commands\Api;

use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class Dns extends BaseApiCommand
{
    protected string $resource = 'dns';

    protected function renderCustom(array $data): void
    {
        $payload = $data['data'] ?? $data;

        if ($zone = $payload['zone'] ?? null) {
            note('Zone: '.($zone['name'] ?? '-').'  ('.($zone['provider_slug'] ?? '-').')');
        }

        $records = $payload['records'] ?? [];

        if (empty($records)) {
            warning('No DNS records found.');
        } else {
            table(
                ['Type', 'Name', 'Content', 'TTL'],
                collect($records)->map(fn ($record) => [
                    $record['type'] ?? '-',
                    $record['shortName'] ?? $record['name'] ?? '-',
                    str((string) ($record['content'] ?? '-'))->limit(60)->toString(),
                    $record['ttl'] ?? '-',
                ])->all()
            );
        }

        $warnings = collect($payload['warnings'] ?? [])
            ->map(fn ($warning) => $warning['message'] ?? (is_string($warning) ? $warning : json_encode($warning)));

        if ($warnings->isNotEmpty()) {
            warning($warnings->implode(PHP_EOL));
        }
    }
}
