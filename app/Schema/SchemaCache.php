<?php

namespace App\Schema;

use App\Actions\RefreshSchema;
use App\Exceptions\StepException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The compact API schema the commands are generated from: the copy fetched into ~/.rocketeers/cache,
 * or the snapshot bundled with the build, so every command exists before the first refresh. A command that
 * needs an endpoint newer than that copy asks findOrRefresh(), which fetches the schema at most once per run.
 */
class SchemaCache
{
    public const string PATH = 'cache/api-v1.json';

    private const array GROUP_ORDER = ['Infrastructure', 'Team resources', 'Resources'];

    /** @var array<string, mixed>|null */
    private ?array $schema = null;

    /** @var array<string, Operation>|null */
    private ?array $operations = null;

    private bool $refreshed = false;

    public static function bundledPath(): string
    {
        return base_path('resources/api-v1.json');
    }

    /** @return array<string, Operation> */
    public function operations(): array
    {
        return $this->operations ??= collect($this->schema()['operations'] ?? [])
            ->map(Operation::fromArray(...))
            ->keyBy(fn (Operation $operation): string => $operation->command)
            ->all();
    }

    public function find(string $command): ?Operation
    {
        return $this->operations()[$command] ?? null;
    }

    public function findByRoute(string $routeName): ?Operation
    {
        return collect($this->operations())->first(fn (Operation $operation): bool => $operation->name === $routeName);
    }

    public function findOrRefresh(string $routeName): ?Operation
    {
        $operation = $this->findByRoute($routeName);

        if ($operation !== null || $this->refreshed) {
            return $operation;
        }

        $this->refreshed = true;

        try {
            (new RefreshSchema)();
        } catch (StepException) {
            return null;
        }

        return $this->findByRoute($routeName);
    }

    public function listFor(Operation $operation, string $parameter): ?Operation
    {
        $prefix = Str::before($operation->path, '/{'.$parameter.'}');

        if ($prefix !== $operation->path) {
            $scoped = $this->readAt($prefix);

            if ($scoped !== null) {
                return $scoped;
            }
        }

        return $this->readAt('/{team}/'.Str::plural(Str::kebab($parameter)));
    }

    public function listForField(Field $field): ?Operation
    {
        return $field->relation === null ? null : $this->findByRoute($field->relation['operation']);
    }

    /** @return array<int, Operation> */
    public function relatedTo(Operation $operation): array
    {
        return array_values(array_filter(
            $this->operations(),
            fn (Operation $candidate): bool => $candidate->name !== $operation->name
                && ($candidate->path === $operation->path || str_starts_with($candidate->path, $operation->path.'/')),
        ));
    }

    /** @return array<string, array<string, array<int, Operation>>> */
    public function groupedByItem(): array
    {
        $groups = $this->schema()['groups'] ?? [];

        return collect($this->operations())
            ->groupBy(fn (Operation $operation): string => $operation->item)
            ->sortKeys()
            ->groupBy(fn ($operations, string $item): string => $groups[$item] ?? 'Resources', preserveKeys: true)
            ->sortBy(fn ($items, string $group): int => in_array($group, self::GROUP_ORDER, true) ? (int) array_search($group, self::GROUP_ORDER, true) : count(self::GROUP_ORDER))
            ->map(fn ($items) => $items->map(fn ($operations) => $operations->values()->all())->all())
            ->all();
    }

    public function permissionLabel(string $permission): ?string
    {
        return $this->schema()['abilities'][$permission] ?? null;
    }

    public function version(): ?string
    {
        return $this->schema()['version'] ?? null;
    }

    public function etag(): ?string
    {
        return $this->schema()['etag'] ?? null;
    }

    public function isStale(): bool
    {
        $fetchedAt = $this->schema()['fetched_at'] ?? null;

        return $fetchedAt === null || $fetchedAt < time() - (int) config('rocketeers.schema_max_age');
    }

    /** @param array<string, mixed> $compact */
    public function store(array $compact, ?string $etag): void
    {
        $this->write([...$compact, 'etag' => $etag, 'fetched_at' => time()]);
    }

    public function touch(): void
    {
        $this->write([...$this->schema(), 'fetched_at' => time()]);
    }

    public function flush(): void
    {
        $this->schema = null;
        $this->operations = null;
    }

    /** @param array<string, mixed> $schema */
    private function write(array $schema): void
    {
        Storage::put(self::PATH, (string) json_encode($schema, JSON_UNESCAPED_SLASHES));

        $this->flush();
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $contents = Storage::exists(self::PATH) ? Storage::get(self::PATH) : @file_get_contents(self::bundledPath());
        $schema = is_string($contents) ? json_decode($contents, true) : null;

        return $this->schema = is_array($schema) ? $schema : [];
    }

    private function readAt(string $path): ?Operation
    {
        return collect($this->operations())
            ->filter(fn (Operation $operation): bool => $operation->isRead() && $operation->path === $path)
            ->sortByDesc(fn (Operation $operation): bool => $operation->isList())
            ->first();
    }
}
