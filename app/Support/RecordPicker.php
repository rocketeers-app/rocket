<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Schema\Operation;
use Illuminate\Support\Str;

use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\search;

/**
 * A searchable, paged select over a list operation that loads only each record's label and hands
 * back its id. The first page is shown straight away; typing asks the server for matches.
 */
class RecordPicker
{
    private const string NONE = 'id:';

    /** @var array<string, string> */
    private array $labels = [];

    public function __construct(private readonly RecordFinder $finder) {}

    public function labelFor(?string $id): ?string
    {
        return $id === null ? null : ($this->labels[$id] ?? $id);
    }

    /** @param array<string, string> $pathValues */
    public function pick(Operation $list, array $pathValues, string $label, bool $required = true): ?string
    {
        $first = $this->finder->search($list, $pathValues);

        if ($first['records'] === [] && $required) {
            throw new ApiException("There is nothing to choose for {$label} yet.", 404);
        }

        $value = search(
            label: $label,
            options: fn (string $query): array => $this->options($query === '' ? $first : $this->finder->search($list, $pathValues, $query), $required),
            placeholder: 'Type to search',
            scroll: 10,
            hint: $this->hint($first),
            required: $required,
        );

        $id = Str::after((string) $value, self::NONE);

        return $id === '' ? null : $id;
    }

    /**
     * @param  array<string, string>  $pathValues
     * @return array<int, string>
     */
    public function pickMany(Operation $list, array $pathValues, string $label, bool $required = false): array
    {
        $first = $this->finder->search($list, $pathValues);

        $values = multisearch(
            label: $label,
            options: fn (string $query): array => $this->options($query === '' ? $first : $this->finder->search($list, $pathValues, $query), true),
            placeholder: 'Type to search',
            scroll: 10,
            required: $required,
            hint: $this->hint($first),
        );

        return array_values(array_map(fn (mixed $value): string => Str::after((string) $value, self::NONE), $values));
    }

    /**
     * @param  array{records: array<int, array<string, mixed>>, total: int}  $result
     * @return array<string, string>
     */
    private function options(array $result, bool $required): array
    {
        $options = $required ? [] : [self::NONE => '— none —'];

        foreach ($result['records'] as $record) {
            $id = Records::id($record);

            if ($id !== null) {
                $options[self::NONE.$id] = Records::display($record);
                $this->labels[$id] = Records::label($record);
            }
        }

        return $options;
    }

    /** @param array{records: array<int, array<string, mixed>>, total: int} $result */
    private function hint(array $result): string
    {
        return count($result['records']).' of '.$result['total'].' · type to search, ↓ to choose';
    }
}
