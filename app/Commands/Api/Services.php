<?php

namespace App\Commands\Api;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class Services extends BaseApiCommand
{
    protected string $resource = 'services';

    protected function renderCustom(array $data): void
    {
        $payload = $data['data'] ?? $data;

        if (isset($payload['message']) && ! isset($payload['services'])) {
            info($payload['message']);

            return;
        }

        $services = $payload['services'] ?? [];

        if (empty($services)) {
            warning('No services installed.');

            return;
        }

        table(
            ['Service', 'Version'],
            collect($services)->map(fn ($service) => is_array($service)
                ? [$service['service_type'] ?? '-', $service['version'] ?? '-']
                : [$service, '-'])->all()
        );
    }
}
