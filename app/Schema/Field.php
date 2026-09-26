<?php

namespace App\Schema;

use Illuminate\Support\Str;

/** One body or query field of an operation, as the API schema describes it. */
final readonly class Field
{
    /**
     * @param  array<int, mixed>|null  $enum
     * @param  array<int, mixed>|null  $itemsEnum
     * @param  array{item: string, operation: string, value?: string}|null  $relation
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public bool $required = false,
        public bool $nullable = false,
        public ?array $enum = null,
        public ?string $itemsType = null,
        public ?array $itemsEnum = null,
        public ?array $relation = null,
        public ?string $description = null,
        public mixed $default = null,
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public ?int $maxLength = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromSchema(array $data): self
    {
        $types = array_values(array_filter(explode('|', (string) ($data['type'] ?? 'string')), fn (string $type): bool => $type !== 'null'));
        $enum = array_values(array_filter($data['enum'] ?? [], fn (mixed $value): bool => $value !== '' && $value !== null));
        $itemsEnum = array_values(array_filter($data['items']['enum'] ?? [], fn (mixed $value): bool => $value !== '' && $value !== null));

        return new self(
            name: (string) $data['name'],
            type: $types[0] ?? 'string',
            required: (bool) ($data['required'] ?? false),
            nullable: (bool) ($data['nullable'] ?? false) || str_contains((string) ($data['type'] ?? ''), 'null'),
            enum: $enum === [] ? null : $enum,
            itemsType: isset($data['items']['type']) ? explode('|', (string) $data['items']['type'])[0] : null,
            itemsEnum: $itemsEnum === [] ? null : $itemsEnum,
            relation: isset($data['relation']['operation']) ? $data['relation'] : null,
            description: isset($data['description']) ? trim((string) $data['description']) : null,
            default: $data['default'] ?? null,
            minimum: $data['minimum'] ?? null,
            maximum: $data['maximum'] ?? null,
            maxLength: $data['maxLength'] ?? null,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter(get_object_vars($this), fn (mixed $value): bool => $value !== null && $value !== false);
    }

    public function withRelation(?array $relation): self
    {
        return new self(...[...get_object_vars($this), 'relation' => $relation]);
    }

    public function isArray(): bool
    {
        return $this->type === 'array';
    }

    public function isRelation(): bool
    {
        return $this->relation !== null;
    }

    public function referencesId(): bool
    {
        return preg_match('/_ids?$/', $this->name) === 1;
    }

    public function stem(): string
    {
        return (string) preg_replace('/_ids?$/', '', $this->name);
    }

    public function label(): string
    {
        $label = Str::headline($this->referencesId() ? $this->stem() : $this->name);

        return $this->isArray() && $this->referencesId() ? Str::plural($label) : $label;
    }

    public function hint(): string
    {
        return Str::of((string) $this->description)->before("\n")->limit(80)->toString();
    }

    public function preferredOption(): string
    {
        $name = $this->referencesId() ? $this->stem().($this->isArray() ? 's' : '') : $this->name;

        return Str::of($name)->replace('_', '-')->lower()->toString();
    }

    public function isSecret(): bool
    {
        return Str::contains($this->name, ['password', 'secret', 'token', 'private_key']);
    }
}
