<?php

namespace App\Commands\Api;

use App\Actions\ApiRequest;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

class Teams extends Command
{
    use WithSteps;

    protected $signature = 'api:teams
        {--json : Output raw JSON}';

    protected $description = 'List your Rocketeers teams';

    public function handle(): int
    {
        if ($this->option('json')) {
            try {
                $teams = ApiRequest::run('me/teams');
            } catch (\Throwable $e) {
                $this->output->writeln(json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }

            $this->output->writeln(json_encode($teams, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->startProgress(1);

        $teams = $this->step('Fetching teams', fn () => ApiRequest::run('me/teams'));

        $this->finishProgress();

        if (empty($teams)) {
            $this->newLine();
            $this->warn('You are not a member of any teams.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Name', 'Slug', 'Created'],
            collect($teams)->map(fn ($team) => [
                $team['name'],
                $team['slug'],
                $team['created_at'] ?? '',
            ])->toArray()
        );

        return self::SUCCESS;
    }
}
