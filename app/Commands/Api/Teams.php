<?php

namespace App\Commands\Api;

use App\Actions\ApiRequest;
use App\Commands\Api\Concerns\InteractsWithApi;
use App\Exceptions\StepException;
use Illuminate\Console\Command;

class Teams extends Command
{
    use InteractsWithApi;

    protected $signature = 'teams {--json : Output raw JSON}';

    protected $description = 'List your Rocketeers teams';

    public function handle(): int
    {
        try {
            $teams = ApiRequest::make()->paginated('me/teams');
        } catch (StepException $e) {
            return $this->respondWithError($e->getMessage());
        }

        if ($this->option('json')) {
            return $this->outputJson($teams);
        }

        $this->renderList($teams, [
            'Name' => 'name',
            'Slug' => 'slug',
            'Created' => 'created_at',
        ], 'teams');

        return self::SUCCESS;
    }
}
