<?php

namespace App\Support;

use App\Schema\Field;
use InvalidArgumentException;

/** Casts what came in on the command line (`--processes=2`, `--domains=a,b`) to what the field expects. */
class FieldValue
{
    public static function cast(Field $field, mixed $raw): mixed
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        if ($raw === 'null' && $field->nullable) {
            return null;
        }

        return match ($field->type) {
            'boolean' => self::boolean($field, $raw),
            'integer' => self::number($field, $raw, integer: true),
            'number' => self::number($field, $raw, integer: false),
            'array' => self::list($field, $raw),
            'object', 'mixed' => self::json($raw),
            default => self::enum($field->enum, self::scalar($raw), $field),
        };
    }

    public static function display(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES) ?: '',
            $value === null => '—',
            default => (string) $value,
        };
    }

    private static function boolean(Field $field, mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        $value = filter_var(self::scalar($raw), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($value === null) {
            throw new InvalidArgumentException("{$field->name} must be true or false.");
        }

        return $value;
    }

    private static function number(Field $field, mixed $raw, bool $integer): int|float
    {
        $value = self::scalar($raw);

        if (! is_numeric($value) || ($integer && (string) (int) $value !== ltrim((string) $value, '+'))) {
            throw new InvalidArgumentException("{$field->name} must be ".($integer ? 'a whole number.' : 'a number.'));
        }

        return $integer ? (int) $value : (float) $value;
    }

    /** @return array<int, mixed> */
    private static function list(Field $field, mixed $raw): array
    {
        if (is_string($raw) && str_starts_with(ltrim($raw), '[')) {
            $decoded = json_decode($raw, true);

            if (! is_array($decoded)) {
                throw new InvalidArgumentException("{$field->name} is not valid JSON.");
            }

            return $decoded;
        }

        $items = collect((array) $raw)
            ->flatMap(fn (mixed $item): array => is_string($item) ? explode(',', $item) : [$item])
            ->map(fn (mixed $item): mixed => is_string($item) ? trim($item) : $item)
            ->reject(fn (mixed $item): bool => $item === '')
            ->values();

        return $items->map(fn (mixed $item): mixed => match ($field->itemsType) {
            'integer' => is_numeric($item) ? (int) $item : $item,
            'object' => is_string($item) ? self::json($item) : $item,
            default => self::enum($field->itemsEnum, $item, $field),
        })->all();
    }

    private static function json(mixed $raw): mixed
    {
        if (! is_string($raw)) {
            return $raw;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    /** @param array<int, mixed>|null $enum */
    private static function enum(?array $enum, mixed $value, Field $field): mixed
    {
        if ($enum === null) {
            return $value;
        }

        foreach ($enum as $option) {
            if ((string) $option === (string) $value) {
                return $option;
            }
        }

        throw new InvalidArgumentException("{$field->name} must be one of: ".implode(', ', array_map('strval', $enum)).'.');
    }

    private static function scalar(mixed $raw): mixed
    {
        return is_array($raw) ? (end($raw) ?: null) : $raw;
    }
}
