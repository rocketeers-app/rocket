<?php

namespace App\Commands\Api;

use App\Actions\ApiRequest;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

class Me extends Command
{
    use WithSteps;

    protected $signature = 'api:me
        {--json : Output raw JSON}';

    protected $description = 'Show your Rocketeers profile';

    public function handle(): int
    {
        if ($this->option('json')) {
            try {
                $user = ApiRequest::run('me');
            } catch (\Throwable $e) {
                $this->output->writeln(json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }

            $this->output->writeln(json_encode($user, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->startProgress(1);

        $user = $this->step('Fetching profile', fn () => ApiRequest::run('me'));

        $this->finishProgress();

        $this->newLine();
        $this->table(
            ['Field', 'Value'],
            [
                ['Name', ($user['firstname'] ?? '').' '.($user['lastname'] ?? '')],
                ['Email', $user['email'] ?? ''],
                ['Verified', $user['email_verified_at'] ?? 'No'],
                ['Member since', $user['created_at'] ?? ''],
            ]
        );

        return self::SUCCESS;
    }
}
