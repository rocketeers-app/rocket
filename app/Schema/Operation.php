<?php

namespace App\Schema;

use Illuminate\Support\Str;

/** One team API operation from the schema, with the command name the CLI registers it under. */
final readonly class Operation
{
    private const array DESTRUCTIVE_ACTIONS = ['reboot', 'reset', 'reprovision', 'restore'];

    private const array PAGINATION_QUERY = ['page', 'per_page'];

    /**
     * @param  array<int, Field>  $queryFields
     * @param  array<int, Field>  $bodyFields
     */
    public function __construct(
        public string $name,
        public string $command,
        public string $item,
        public string $method,
        public string $path,
        public string $summary,
        public ?string $permission = null,
        public ?string $permissionLabel = null,
        public string $envelope = 'none',
        public int $status = 200,
        public array $queryFields = [],
        public array $bodyFields = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            command: $data['command'],
            item: $data['item'],
            method: $data['method'],
            path: $data['path'],
            summary: $data['summary'] ?? '',
            permission: $data['permission'] ?? null,
            permissionLabel: $data['permission_label'] ?? null,
            envelope: $data['envelope'] ?? 'none',
            status: (int) ($data['status'] ?? 200),
            queryFields: array_map(Field::fromArray(...), $data['query'] ?? []),
            bodyFields: array_map(Field::fromArray(...), $data['body'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'command' => $this->command,
            'item' => $this->item,
            'method' => $this->method,
            'path' => $this->path,
            'summary' => $this->summary,
            'permission' => $this->permission,
            'permission_label' => $this->permissionLabel,
            'envelope' => $this->envelope,
            'status' => $this->status,
            'query' => array_map(fn (Field $field): array => $field->toArray(), $this->queryFields),
            'body' => array_map(fn (Field $field): array => $field->toArray(), $this->bodyFields),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @return array<int, string> */
    public function pathParameters(): array
    {
        preg_match_all('/\{([^}]+)\}/', $this->path, $matches);

        return array_values(array_filter($matches[1], fn (string $name): bool => $name !== 'team'));
    }

    public function action(): string
    {
        return Str::afterLast($this->command, ':');
    }

    public function isRead(): bool
    {
        return $this->method === 'GET';
    }

    public function isList(): bool
    {
        return $this->isRead() && ($this->envelope === 'paginated' || $this->action() === 'list');
    }

    public function isDestructive(): bool
    {
        return $this->method === 'DELETE' || in_array($this->action(), self::DESTRUCTIVE_ACTIONS, true);
    }

    public function isQueued(): bool
    {
        return $this->status === 202;
    }

    /** @return array<int, Field> */
    public function filterFields(): array
    {
        return array_values(array_filter(
            $this->queryFields,
            fn (Field $field): bool => ! in_array($field->name, self::PAGINATION_QUERY, true),
        ));
    }

    /** @return array<int, Field> */
    public function inputFields(): array
    {
        $scoped = array_map(fn (string $parameter): string => Str::snake($parameter), $this->pathParameters());

        return array_values(array_filter(
            $this->bodyFields,
            fn (Field $field): bool => ! ($field->referencesId() && ! $field->isArray() && in_array($field->stem(), $scoped, true)),
        ));
    }
}
