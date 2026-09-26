<?php

namespace App\Support;

use Illuminate\Support\Str;

/** How a record from any list reads to a person: its label, a short hint, and what identifies it. */
class Records
{
    public const array LABEL_KEYS = ['name', 'label', 'fqdn', 'domain', 'slug', 'title', 'short_hash', 'command', 'key', 'id'];

    private const array HINT_KEYS = ['ip', 'ipv4', 'ip_address', 'fqdn', 'slug', 'type', 'status'];

    /** @param array<string, mixed> $record */
    public static function label(array $record): string
    {
        foreach (self::LABEL_KEYS as $key) {
            if (isset($record[$key]) && is_scalar($record[$key]) && (string) $record[$key] !== '') {
                return (string) $record[$key];
            }
        }

        return '(unnamed)';
    }

    /** @param array<string, mixed> $record */
    public static function labelKey(array $record): ?string
    {
        foreach (self::LABEL_KEYS as $key) {
            if (isset($record[$key]) && is_scalar($record[$key]) && (string) $record[$key] !== '') {
                return $key;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $record */
    public static function hint(array $record): ?string
    {
        $label = self::label($record);

        foreach (self::HINT_KEYS as $key) {
            $value = $record[$key] ?? null;

            if (is_scalar($value) && (string) $value !== '' && (string) $value !== $label) {
                return (string) $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $record */
    public static function id(array $record): ?string
    {
        $id = $record['id'] ?? $record['uuid'] ?? null;

        return is_scalar($id) ? (string) $id : null;
    }

    /** @param array<string, mixed> $record */
    public static function display(array $record): string
    {
        $hint = self::hint($record);

        return self::label($record).($hint === null ? '' : "  ({$hint})");
    }

    /** @param array<string, mixed> $record */
    public static function matchesExactly(array $record, string $value): bool
    {
        $needle = mb_strtolower($value);

        foreach (['id', 'uuid', 'slug', 'name', 'label', 'fqdn', 'domain', 'short_hash'] as $key) {
            if (isset($record[$key]) && is_scalar($record[$key]) && mb_strtolower((string) $record[$key]) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $record */
    public static function contains(array $record, string $query): bool
    {
        return $query === '' || Str::contains(mb_strtolower(self::display($record)), mb_strtolower($query));
    }

    public static function looksLikeId(string $value): bool
    {
        return preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9A-HJKMNP-TV-Z]{26}|\d+)$/i', $value) === 1;
    }
}
