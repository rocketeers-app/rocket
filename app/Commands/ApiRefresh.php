<?php

namespace App\Commands;

use App\Actions\RefreshSchema;
use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\WithSteps;
use App\Schema\SchemaCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ApiRefresh extends Command
{
    use OutputsJson;
    use WithSteps;

    protected $signature = 'api:refresh {--bundle : Also write the schema into resources/ for the next build}';

    protected $description = 'Fetch the latest API schema the commands are generated from';

    public function handle(): int
    {
        $this->startProgress(1);

        $result = $this->step('Fetching the API schema', fn (): array => (new RefreshSchema)(force: (bool) $this->option('bundle')));

        $this->finishProgress();

        if ($this->option('bundle')) {
            $bundle = collect(json_decode((string) Storage::get(SchemaCache::PATH), true))->except(['etag', 'fetched_at'])->all();

            file_put_contents(SchemaCache::bundledPath(), json_encode($bundle, JSON_UNESCAPED_SLASHES));
        }

        if ($this->wantsJson()) {
            return $this->emitJson(['version' => $result['version'], 'operations' => $result['operations']]);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Schema', (string) $result['version'].($result['changed'] ? '' : ' <fg=gray>(unchanged)</>'));
        $this->components->twoColumnDetail('Operations', (string) $result['operations']);

        return self::SUCCESS;
    }
}
