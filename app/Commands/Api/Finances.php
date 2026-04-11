<?php

namespace App\Commands\Api;

use App\Actions\ApiRequest;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

class Finances extends Command
{
    use WithSteps;

    protected $signature = 'api:finances
        {team : Team slug}
        {--json : Output raw JSON}';

    protected $description = 'Show finance overview for a team';

    public function handle(): int
    {
        $team = $this->argument('team');

        if ($this->option('json')) {
            try {
                $data = ApiRequest::make()->get("{$team}/finances");
            } catch (\Throwable $e) {
                $this->output->writeln(json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }

            $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->startProgress(1);

        $data = $this->step('Fetching finances', fn () => ApiRequest::make()->get("{$team}/finances"));

        $this->finishProgress();

        if (empty($data['data'])) {
            $this->newLine();
            $this->warn('No finance data found.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Category', 'Name', 'Provider', 'Price'],
            collect($data['data'])->map(fn ($item) => [
                $item['category'],
                $item['name'],
                $item['provider_label'] ?? $item['provider'] ?? '-',
                $item['price'] ? '€'.number_format($item['price'], 2) : '-',
            ])->toArray()
        );

        $this->newLine();
        $this->table(
            ['Category', 'Count', 'Total'],
            collect($data['totals'])->map(fn ($t) => [
                $t['category'],
                $t['count'],
                $t['total'] ? '€'.number_format($t['total'], 2) : '-',
            ])->toArray()
        );

        $this->newLine();
        $this->info('Grand total: €'.number_format($data['grand_total'] ?? 0, 2));

        return self::SUCCESS;
    }
}
