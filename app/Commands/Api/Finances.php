<?php

namespace App\Commands\Api;

class Finances extends BaseApiCommand
{
    protected string $resource = 'finances';

    protected function renderCustom(array $data): void
    {
        $items = collect($data['data'] ?? [])->filter(fn ($item) => is_array($item));

        if ($items->isEmpty()) {
            $this->newLine();
            $this->warn('No finance data found.');

            return;
        }

        $this->newLine();
        $this->table(
            ['Category', 'Name', 'Provider', 'Price'],
            $items->map(fn ($item) => [
                $item['category'] ?? '-',
                $item['name'] ?? '-',
                $item['provider_label'] ?? $item['provider'] ?? '-',
                empty($item['price']) ? '-' : '€'.number_format((float) $item['price'], 2),
            ])->all()
        );

        if (! empty($data['totals'])) {
            $this->newLine();
            $this->table(
                ['Category', 'Count', 'Total'],
                collect($data['totals'])->map(fn ($total) => [
                    $total['category'] ?? '-',
                    $total['count'] ?? 0,
                    empty($total['total']) ? '-' : '€'.number_format((float) $total['total'], 2),
                ])->all()
            );
        }

        $this->newLine();
        $this->info('Grand total: €'.number_format((float) ($data['grand_total'] ?? 0), 2));
    }
}
