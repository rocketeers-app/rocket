<?php

namespace App\Console;

use App\Actions\SendApiRequest;
use App\Api\ApiErrorPresenter;
use App\Api\Requests\CallOperation;
use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\ResolvesTeam;
use App\Exceptions\ApiException;
use App\Schema\Field;
use App\Schema\Operation;
use App\Schema\SchemaCache;
use App\Support\FieldPrompter;
use App\Support\FieldValue;
use App\Support\IdentifierResolver;
use App\Support\OutputRenderer;
use App\Support\PermissionGate;
use App\Support\RecordPicker;
use App\Support\Records;
use App\Support\Teams;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Saloon\Http\Response;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

/**
 * One API operation as a command, built from the schema: path parameters become arguments (a slug,
 * name or id; asked for with a search when left out), body fields become options, and whatever is
 * required but missing is prompted for — or reported, when nobody is there to answer.
 */
class OperationCommand extends Command
{
    use OutputsJson;
    use ResolvesTeam;

    private const array RESERVED_OPTIONS = [
        'help', 'quiet', 'silent', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env',
        'team', 'json', 'force', 'data', 'search', 'page', 'per-page',
    ];

    /** @var array<string, string> */
    private array $optionNames = [];

    private RecordPicker $picker;

    /** @var array<string, mixed> */
    private array $team = [];

    public function __construct(private readonly Operation $operation)
    {
        $this->name = $operation->command;
        $this->description = $operation->summary;

        parent::__construct();

        $this->setHelp(trim($operation->method.' '.$operation->path.($operation->permission ? "\nRequires the {$operation->permission} permission." : '')));
    }

    public function operation(): Operation
    {
        return $this->operation;
    }

    public function getDescription(): string
    {
        $allowed = app(PermissionGate::class)->allows($this->operation, app(Teams::class)->currentFromCache());

        return parent::getDescription().($allowed ? '' : '  🔒 no access');
    }

    public function handle(): int
    {
        $this->picker = app(RecordPicker::class);
        $this->team = $this->resolveTeam();

        app(PermissionGate::class)->ensure($this->operation, $this->team);

        $pathValues = ['team' => (string) $this->team['slug']];
        $labels = [];

        foreach ($this->operation->pathParameters() as $parameter) {
            [$pathValues[$parameter], $labels[$parameter]] = $this->pathValue($parameter, $pathValues);
        }

        return $this->operation->isRead()
            ? $this->read($pathValues)
            : $this->write($pathValues, $labels);
    }

    protected function getArguments(): array
    {
        return array_map(
            fn (string $parameter): array => [Str::kebab($parameter), InputArgument::OPTIONAL, 'The '.Str::lower(Str::headline($parameter)).': slug, name or id'],
            $this->operation->pathParameters(),
        );
    }

    protected function getOptions(): array
    {
        $options = [['team', null, InputOption::VALUE_REQUIRED, 'The team slug; defaults to the team `rocket team` saved']];

        if ($this->operation->isList()) {
            $options[] = ['search', null, InputOption::VALUE_REQUIRED, 'Only records matching this'];
            $options[] = ['page', null, InputOption::VALUE_REQUIRED, 'The page to show', 1];
            $options[] = ['per-page', null, InputOption::VALUE_REQUIRED, 'Records per page (at most 50)'];
        }

        foreach ([...$this->operation->filterFields(), ...$this->operation->inputFields()] as $field) {
            $option = $this->optionFor($field);

            if ($option === null) {
                continue;
            }

            $this->optionNames[$field->name] = $option;
            $options[] = [$option, null, InputOption::VALUE_REQUIRED | ($field->isArray() ? InputOption::VALUE_IS_ARRAY : 0), $this->describe($field)];
        }

        if (! $this->operation->isRead()) {
            $options[] = ['data', null, InputOption::VALUE_REQUIRED, 'The whole request body as JSON; options override its fields'];
            $options[] = ['force', null, InputOption::VALUE_NONE, 'Go ahead without asking for confirmation'];
        }

        return $options;
    }

    /**
     * @param  array<string, string>  $pathValues
     * @return array{0: string, 1: string}
     */
    private function pathValue(string $parameter, array $pathValues): array
    {
        $argument = Str::kebab($parameter);
        $value = $this->argument($argument);
        $noun = Str::lower(Str::headline($parameter));
        $list = app(SchemaCache::class)->listFor($this->operation, $parameter);

        if (blank($value)) {
            if (! $this->canPrompt()) {
                throw new ApiException("Missing the {$noun}.", 422, errors: [$argument => ["Pass the {$noun} as an argument: rocket {$this->operation->command} <{$argument}>."]]);
            }

            if ($list === null) {
                $value = text(label: Str::headline($parameter), required: true);

                return [$value, $value];
            }

            $id = (string) $this->picker->pick($list, $pathValues, Str::headline($parameter));

            return [$id, (string) $this->picker->labelFor($id)];
        }

        if ($list === null) {
            return [(string) $value, (string) $value];
        }

        $resolved = app(IdentifierResolver::class)->resolve($list, $pathValues, (string) $value, $noun, $this->canPrompt());

        return [$resolved['id'], $resolved['label']];
    }

    /** @param array<string, string> $pathValues */
    private function read(array $pathValues): int
    {
        $query = [];

        if ($this->operation->isList()) {
            $query = [
                'search' => $this->option('search'),
                'page' => $this->option('page'),
                'per_page' => $this->option('per-page'),
            ];
        }

        foreach ($this->operation->filterFields() as $field) {
            if (isset($this->optionNames[$field->name]) && ! blank($this->option($this->optionNames[$field->name]))) {
                $query[$field->name] = $this->cast($field, $this->option($this->optionNames[$field->name]));
            }
        }

        $response = (new SendApiRequest)(CallOperation::for($this->operation, $pathValues, $query), teamName: $this->team['name']);

        if (! $this->isJson($response)) {
            return $this->wantsJson()
                ? $this->emitJson(['content_type' => $response->header('Content-Type'), 'body' => $response->body()])
                : $this->raw($response->body());
        }

        $body = $response->json();

        if ($this->wantsJson()) {
            return $this->emitJson($body);
        }

        $data = is_array($body) && array_key_exists('data', $body) ? $body['data'] : $body;
        $renderer = app(OutputRenderer::class);

        $this->newLine();

        if (is_array($data) && array_is_list($data)) {
            $renderer->table($this, array_values(array_filter($data, 'is_array')), is_array($body) ? $body : []);

            return self::SUCCESS;
        }

        if (is_array($data)) {
            $renderer->detail($this, $data);

            if ($this->operation->action() === 'read') {
                $this->related();
            }

            return self::SUCCESS;
        }

        $this->line('  '.FieldValue::display($data));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $pathValues
     * @param  array<string, string>  $labels
     */
    private function write(array $pathValues, array $labels): int
    {
        $body = $this->collectBody($pathValues);
        $target = $labels === [] ? null : end($labels);

        if (! $this->confirmed($body, $labels, $target)) {
            $this->components->warn('Nothing was changed.');

            return self::SUCCESS;
        }

        while (true) {
            $response = (new SendApiRequest)(CallOperation::for($this->operation, $pathValues, [], $body), teamName: $this->team['name'], allowFailure: true);

            if ($response->status() !== 422 || ! $this->canPrompt()) {
                break;
            }

            $exception = ApiErrorPresenter::fromResponse($response, $this->team['name']);
            $again = $this->fieldsIn($exception->errors);

            if ($again === []) {
                throw $exception;
            }

            $this->reportFailure($exception);

            foreach ($again as $field) {
                $body[$field->name] = app(FieldPrompter::class, ['picker' => $this->picker])
                    ->prompt($field, app(SchemaCache::class)->listForField($field), $pathValues, $body[$field->name] ?? null);
            }
        }

        if ($response->failed()) {
            throw ApiErrorPresenter::fromResponse($response, $this->team['name']);
        }

        $result = $this->isJson($response) ? $response->json() : null;

        if ($this->wantsJson()) {
            return $this->emitJson($result ?? ['status' => $response->status()]);
        }

        $what = $this->operation->summary.($target === null ? '' : " {$target}");

        $this->newLine();
        $this->line('  <fg=green>✓</> '.($this->operation->isQueued() || $response->status() === 202 ? "Queued: {$what}" : $what));

        $data = is_array($result) ? ($result['data'] ?? $result) : null;

        if (is_array($data) && $data !== [] && ! array_is_list($data) && Records::labelKey($data) !== null) {
            $this->newLine();
            app(OutputRenderer::class)->detail($this, $data);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $pathValues
     * @return array<string, mixed>
     */
    private function collectBody(array $pathValues): array
    {
        $body = $this->dataOption();
        $fields = $this->operation->inputFields();
        $errors = [];

        foreach ($fields as $field) {
            $option = $this->optionNames[$field->name] ?? null;
            $raw = $option === null ? null : $this->option($option);

            if ($raw === null || $raw === []) {
                continue;
            }

            try {
                $body[$field->name] = $this->castRelation($field, $this->cast($field, $raw), $pathValues);
            } catch (InvalidArgumentException $exception) {
                $errors[$field->name] = [$exception->getMessage()];
            }
        }

        if ($errors !== []) {
            throw new ApiException('Some options are not valid.', 422, errors: $errors);
        }

        $missing = array_values(array_filter($fields, fn (Field $field): bool => $field->required && ! array_key_exists($field->name, $body)));

        if ($missing !== [] && ! $this->canPrompt()) {
            throw new ApiException('Missing required fields.', 422, errors: collect($missing)
                ->mapWithKeys(fn (Field $field): array => [$field->name => ['Required: pass --'.($this->optionNames[$field->name] ?? $field->name).'=…']])
                ->all());
        }

        if (! $this->canPrompt()) {
            return $body;
        }

        $guided = $missing !== [] || $body === [];
        $prompter = app(FieldPrompter::class, ['picker' => $this->picker]);
        $schema = app(SchemaCache::class);

        foreach ($missing as $field) {
            $body[$field->name] = $prompter->prompt($field, $schema->listForField($field), $pathValues);
        }

        $optional = array_values(array_filter($fields, fn (Field $field): bool => ! array_key_exists($field->name, $body)));

        if ($optional === [] || ! $guided) {
            return $this->withoutEmpty($body, $fields);
        }

        $chosen = multiselect(
            label: $missing === [] && $body === [] ? 'Which fields do you want to set?' : 'Set optional fields?',
            options: collect($optional)->mapWithKeys(fn (Field $field): array => [$field->name => $field->label()])->all(),
            scroll: 12,
            hint: 'Space to select, enter to continue',
        );

        foreach ($optional as $field) {
            if (in_array($field->name, $chosen, true)) {
                $body[$field->name] = $prompter->prompt($field, $schema->listForField($field), $pathValues);
            }
        }

        return $this->withoutEmpty($body, $fields);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $labels
     */
    private function confirmed(array $body, array $labels, ?string $target): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if ($this->operation->isDestructive()) {
            if (! $this->canPrompt()) {
                throw new ApiException(ucfirst($this->operation->action()).' needs confirmation. Pass --force to go ahead.', 409);
            }

            return confirm(
                label: $this->operation->summary.($target === null ? '' : " {$target}").'?',
                default: false,
                hint: "In team {$this->team['name']}. This cannot be undone.",
            );
        }

        if (! $this->canPrompt()) {
            return true;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>'.$this->operation->summary.'</>', 'team '.$this->team['name']);

        foreach ($labels as $parameter => $label) {
            $this->components->twoColumnDetail(Str::headline($parameter), $label);
        }

        foreach ($body as $name => $value) {
            $field = collect($this->operation->bodyFields)->firstWhere('name', $name);
            $shown = $field instanceof Field && $field->isSecret() ? '••••••' : FieldValue::display($this->labelFor($field, $value));
            $this->components->twoColumnDetail($field instanceof Field ? $field->label() : $name, $shown);
        }

        return confirm(label: 'Send this?', default: true);
    }

    private function related(): void
    {
        $related = app(SchemaCache::class)->relatedTo($this->operation);

        if ($related === []) {
            return;
        }

        $gate = app(PermissionGate::class);

        $this->newLine();
        $this->line('  <options=bold>Commands for this '.Str::lower(Str::headline(Str::singular($this->operation->item))).'</>');

        foreach ($related as $operation) {
            $this->components->twoColumnDetail(
                $operation->command,
                $gate->allows($operation, $this->team) ? '<fg=green>✓</>' : '<fg=yellow>🔒 '.$operation->permission.'</>',
            );
        }
    }

    private function optionFor(Field $field): ?string
    {
        $taken = [...self::RESERVED_OPTIONS, ...array_values($this->optionNames), ...array_map(fn (string $parameter): string => Str::kebab($parameter), $this->operation->pathParameters())];

        foreach ([$field->preferredOption(), str_replace('_', '-', $field->name)] as $candidate) {
            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function describe(Field $field): string
    {
        $parts = [$field->label()];

        if ($field->required) {
            $parts[] = '(required)';
        }

        if ($field->enum !== null || $field->itemsEnum !== null) {
            $parts[] = 'one of: '.Str::limit(implode(', ', array_map('strval', $field->enum ?? $field->itemsEnum)), 120);
        } elseif ($field->isRelation()) {
            $parts[] = '— slug, name or id';
        } elseif ($field->type === 'boolean') {
            $parts[] = '— true or false';
        } elseif ($field->isArray()) {
            $parts[] = '— repeat the option or separate with commas';
        }

        return implode(' ', $parts);
    }

    private function cast(Field $field, mixed $raw): mixed
    {
        return FieldValue::cast($field, $raw);
    }

    /** @param array<string, string> $pathValues */
    private function castRelation(Field $field, mixed $value, array $pathValues): mixed
    {
        $list = app(SchemaCache::class)->listForField($field);

        if ($list === null || $value === null) {
            return $value;
        }

        $resolver = app(IdentifierResolver::class);
        $noun = Str::lower(Str::headline($field->stem()));
        $resolve = fn (mixed $one): string => $resolver->resolve($list, $pathValues, (string) $one, $noun, $this->canPrompt())['id'];

        return is_array($value) ? array_map($resolve, $value) : $resolve($value);
    }

    /** @return array<string, mixed> */
    private function dataOption(): array
    {
        $data = $this->option('data');

        if (blank($data)) {
            return [];
        }

        $decoded = json_decode((string) $data, true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException('--data must be a JSON object.', 422);
        }

        return $decoded;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @return array<int, Field>
     */
    private function fieldsIn(array $errors): array
    {
        $names = collect(array_keys($errors))->map(fn (string $key): string => Str::before($key, '.'))->unique()->all();

        return array_values(array_filter($this->operation->inputFields(), fn (Field $field): bool => in_array($field->name, $names, true)));
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<int, Field>  $fields
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $body, array $fields): array
    {
        $nullable = collect($fields)->filter(fn (Field $field): bool => $field->nullable)->pluck('name')->all();

        return array_filter($body, fn (mixed $value, string $name): bool => $value !== null || in_array($name, $nullable, true), ARRAY_FILTER_USE_BOTH);
    }

    private function labelFor(?Field $field, mixed $value): mixed
    {
        if (! $field instanceof Field || ! $field->isRelation()) {
            return $value;
        }

        return is_array($value) ? array_map(fn (mixed $id): string => (string) $this->picker->labelFor((string) $id), $value) : $this->picker->labelFor((string) $value);
    }

    private function isJson(Response $response): bool
    {
        $type = (string) $response->header('Content-Type');

        return str_contains($type, 'json') || ($type === '' && json_validate($response->body()));
    }

    private function raw(string $body): int
    {
        $this->output->write($body, false, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
