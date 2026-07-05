<?php

namespace App\Commands\Api;

class Services extends BaseApiCommand
{
    protected string $resource = 'services';

    protected function renderCustom(array $data): void
    {
        if (isset($data['message']) && ! isset($data['services'])) {
            $this->newLine();
            $this->info($data['message']);

            return;
        }

        $services = $data['services'] ?? [];

        if (empty($services)) {
            $this->newLine();
            $this->warn('No services installed.');
        } else {
            $this->newLine();
            $this->table(
                ['Installed service'],
                collect($services)->map(fn ($service) => [is_array($service) ? json_encode($service) : $service])->all()
            );
        }

        if (! empty($data['available_services'])) {
            $this->newLine();
            $this->line('<comment>Available:</comment> '.collect($data['available_services'])
                ->map(fn ($service) => is_array($service) ? json_encode($service) : $service)
                ->implode(', '));
        }
    }
}
