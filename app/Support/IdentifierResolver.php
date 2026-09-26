<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Schema\Operation;

use function Laravel\Prompts\select;

/**
 * Turns what a person types (`acme-production`, `Acme Production`) into the id the API routes bind on.
 * An id passes straight through; a slug, name or label must match one record exactly.
 */
class IdentifierResolver
{
    public function __construct(private readonly RecordFinder $finder) {}

    /**
     * @param  array<string, string>  $pathValues
     * @return array{id: string, label: string}
     */
    public function resolve(Operation $list, array $pathValues, string $value, string $noun, bool $interactive): array
    {
        if (Records::looksLikeId($value)) {
            return ['id' => $value, 'label' => $value];
        }

        $searched = $this->finder->search($list, $pathValues, $value)['records'];
        $matches = $this->exact($searched, $value);
        $pool = $searched;

        if ($matches === []) {
            $pool = $this->finder->all($list, $pathValues);
            $matches = $this->exact($pool, $value);
        }

        if (count($matches) === 1) {
            return $this->pair($matches[0]);
        }

        if ($matches === []) {
            $suggestions = collect($pool)
                ->filter(fn (array $record): bool => Records::contains($record, $value))
                ->take(5)
                ->map(fn (array $record): string => Records::label($record))
                ->implode(', ');

            throw new ApiException("No {$noun} `{$value}` found.".($suggestions === '' ? '' : " Did you mean: {$suggestions}?"), 404);
        }

        if (! $interactive) {
            $labels = collect($matches)->map(fn (array $record): string => Records::display($record).' ['.Records::id($record).']')->implode(', ');

            throw new ApiException("`{$value}` matches several {$noun}s: {$labels}. Pass the id instead.", 409);
        }

        $options = collect($matches)->mapWithKeys(fn (array $record, int $index): array => ["#{$index}" => Records::display($record)])->all();
        $chosen = select(label: "Which {$noun} did you mean?", options: $options);

        return $this->pair($matches[(int) ltrim((string) $chosen, '#')]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function exact(array $records, string $value): array
    {
        return array_values(array_filter($records, fn (array $record): bool => Records::matchesExactly($record, $value)));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{id: string, label: string}
     */
    private function pair(array $record): array
    {
        return ['id' => (string) Records::id($record), 'label' => Records::label($record)];
    }
}
