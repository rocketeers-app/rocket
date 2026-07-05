<?php

namespace App\Commands\Api;

use App\Actions\ApiRequest;
use App\Commands\Api\Concerns\InteractsWithApi;
use App\Exceptions\StepException;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

/**
 * Raw passthrough for a write verb (POST/PUT/PATCH/DELETE) to any endpoint — the
 * escape hatch for write endpoints without a dedicated per-resource action. The
 * resolved team is prefixed unless the path is absolute (leading "/").
 */
abstract class WriteVerbCommand extends Command
{
    use InteractsWithApi;

    protected string $verb;

    protected bool $destructive = false;

    public function __construct()
    {
        $this->signature = $this->verb.'
            {path : API path, e.g. sites or servers/{id}/reboot}
            {--team= : Team slug (defaults to your configured team)}
            {--F|field=* : Body field as key=value (repeatable)}
            {--data= : Raw JSON body}
            {--force : Skip the confirmation prompt}
            {--json : Output raw JSON}';

        $this->description = 'Send a '.strtoupper($this->verb).' request to any endpoint';

        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $path = $this->resolvePath();
            $body = $this->bodyFromOptions();

            if ($this->destructive && ! $this->option('force')
                && ! confirm(strtoupper($this->verb).' '.$path.' — are you sure?', default: false)) {
                note('Aborted.');

                return self::SUCCESS;
            }

            $result = ApiRequest::make()->{$this->verb}($path, $body);
        } catch (StepException $e) {
            return $this->respondWithError($e->getMessage());
        }

        if ($this->option('json')) {
            return $this->outputJson($result);
        }

        $data = $result['data'] ?? $result;

        if (empty($data)) {
            info(strtoupper($this->verb).' '.$path.' — done.');
        } elseif (array_is_list($data)) {
            note(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderSingle($data);
        }

        return self::SUCCESS;
    }
}
