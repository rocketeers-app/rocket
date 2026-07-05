<?php

namespace App\Commands\Api;

use App\Actions\ApiRequest;
use App\Commands\Api\Concerns\InteractsWithApi;
use App\Exceptions\StepException;
use Illuminate\Console\Command;

use function Laravel\Prompts\table;

class Me extends Command
{
    use InteractsWithApi;

    protected $signature = 'me {--json : Output raw JSON}';

    protected $description = 'Show your Rocketeers profile';

    public function handle(): int
    {
        try {
            $user = ApiRequest::make()->single('me');
        } catch (StepException $e) {
            return $this->respondWithError($e->getMessage());
        }

        if ($this->option('json')) {
            return $this->outputJson($user);
        }

        table(['Field', 'Value'], [
            ['Name', trim(($user['firstname'] ?? '').' '.($user['lastname'] ?? '')) ?: '-'],
            ['Email', $user['email'] ?? '-'],
            ['Verified', $user['email_verified_at'] ?? 'No'],
            ['Member since', $user['created_at'] ?? '-'],
        ]);

        return self::SUCCESS;
    }
}
