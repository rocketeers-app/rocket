<?php

namespace App\Schema;

use Illuminate\Support\Str;

/**
 * Reduces the megabytes of GET /v1/docs to what the CLI reads: the team operations with their fields,
 * and a label per permission. Relation hints the server does not send yet fall back to the convention
 * `{thing}_id` → the list at `/{team}/{things}`.
 */
class SchemaCompactor
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function handle(array $document): array
    {
        $operations = collect($document['items'] ?? [])
            ->flatMap(fn (array $item): array => [
                ...array_map(fn (array $operation): array => [$item['key'], $operation], $item['operations'] ?? []),
                ...collect($item['subitems'] ?? [])
                    ->flatMap(fn (array $subitem): array => array_map(fn (array $operation): array => [$item['key'], $operation], $subitem['operations'] ?? []))
                    ->all(),
            ])
            ->filter(fn (array $pair): bool => str_starts_with($pair[1]['path'] ?? '', '/{team}'))
            ->map(fn (array $pair): Operation => $this->operation($pair[0], $pair[1]))
            ->values();

        $lists = $operations
            ->filter(fn (Operation $operation): bool => $operation->isRead() && preg_match('#^/\{team\}/[a-z-]+$#', $operation->path) === 1)
            ->mapWithKeys(fn (Operation $operation): array => [Str::after($operation->path, '/{team}/') => $operation->name]);

        return [
            'version' => $document['version'] ?? null,
            'groups' => collect($document['items'] ?? [])
                ->filter(fn (array $item): bool => isset($item['key'], $item['group']))
                ->mapWithKeys(fn (array $item): array => [$item['key'] => $item['group']])
                ->all(),
            'abilities' => collect($document['abilities'] ?? [])
                ->flatten(1)
                ->filter(fn (mixed $ability): bool => is_array($ability) && isset($ability['value']))
                ->mapWithKeys(fn (array $ability): array => [$ability['value'] => $ability['label'] ?? $ability['value']])
                ->all(),
            'operations' => $operations
                ->map(fn (Operation $operation): array => $this->withConventionalRelations($operation, $lists->all())->toArray())
                ->all(),
        ];
    }

    /** @param array<string, mixed> $operation */
    private function operation(string $item, array $operation): Operation
    {
        $envelope = $operation['response']['envelope'] ?? 'none';

        return new Operation(
            name: $operation['name'],
            command: CommandNamer::name($operation['name'], $envelope),
            item: $item,
            method: $operation['method'],
            path: $operation['path'],
            summary: rtrim(Str::before(trim((string) ($operation['summary'] ?? '')), "\n"), ' ,;:'),
            permission: $operation['ability']['value'] ?? null,
            permissionLabel: $operation['ability']['label'] ?? null,
            envelope: $envelope,
            status: (int) ($operation['response']['status'] ?? 200),
            queryFields: array_map(Field::fromSchema(...), $operation['query_params'] ?? []),
            bodyFields: array_map(Field::fromSchema(...), $operation['body_params'] ?? []),
        );
    }

    /** @param array<string, string> $lists */
    private function withConventionalRelations(Operation $operation, array $lists): Operation
    {
        $fields = array_map(function (Field $field) use ($lists): Field {
            if ($field->isRelation() || ! $field->referencesId()) {
                return $field;
            }

            $item = str_replace('_', '-', Str::plural($field->stem()));

            return isset($lists[$item]) ? $field->withRelation(['item' => $item, 'operation' => $lists[$item]]) : $field;
        }, $operation->bodyFields);

        return new Operation(...[...get_object_vars($operation), 'bodyFields' => $fields]);
    }
}
