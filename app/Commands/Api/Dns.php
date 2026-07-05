<?php

namespace App\Commands\Api;

class Dns extends BaseApiCommand
{
    protected string $resource = 'dns';

    protected function renderCustom(array $data): void
    {
        if ($zone = $data['zone'] ?? null) {
            $this->newLine();
            $this->table(
                ['Zone', 'Provider'],
                [[$zone['name'] ?? '-', $zone['provider_slug'] ?? '-']]
            );
        }

        $records = $data['records'] ?? [];

        if (empty($records)) {
            $this->newLine();
            $this->warn('No DNS records found.');
        } else {
            $this->newLine();
            $this->table(
                ['Record'],
                collect($records)->map(fn ($record) => [is_array($record) ? json_encode($record) : $record])->all()
            );
        }

        foreach ($data['warnings'] ?? [] as $warning) {
            $this->warn($warning['message'] ?? (is_string($warning) ? $warning : json_encode($warning)));
        }
    }
}
