<?php

namespace App\Commands\Api;

use App\Actions\ApiRequest;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

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

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('metadata', null, InputOption::VALUE_NONE, 'Include results count, meta, and served_in in JSON output');
    }

    public function handle(): int
    {
        $team = $this->argument('team');
        $withMeta = $this->option('metadata');

        if ($this->option('json')) {
            try {
                $result = ApiRequest::make()->paginated($this->endpoint($team), withMeta: $withMeta);
            } catch (\Throwable $e) {
                $this->output->writeln(json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }

            $this->output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

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
