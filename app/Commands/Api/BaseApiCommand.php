<?php

namespace App\Commands\Api;

use App\Actions\ApiRequest;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

abstract class BaseApiCommand extends Command
{
    use WithSteps;

    abstract protected function endpoint(string $team): string;

    abstract protected function tableHeaders(): array;

    abstract protected function tableRow(array $item): array;

    protected function resourceLabel(): string
    {
        return class_basename(static::class);
    }

    public function handle(): int
    {
        $team = $this->argument('team');

        if ($this->option('json')) {
            try {
                $data = ApiRequest::make()->paginated($this->endpoint($team));
            } catch (\Throwable $e) {
                $this->output->writeln(json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }

            $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->startProgress(1);

        $data = $this->step('Fetching '.$this->resourceLabel(), fn () => ApiRequest::make()->paginated($this->endpoint($team)));

        $this->finishProgress();

        if (empty($data)) {
            $this->newLine();
            $this->warn('No '.$this->resourceLabel().' found.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            $this->tableHeaders(),
            collect($data)->map(fn ($item) => $this->tableRow($item))->toArray()
        );

        return self::SUCCESS;
    }
}
