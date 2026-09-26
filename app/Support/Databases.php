<?php

namespace App\Support;

/**
 * Reads a database record from the API: which engine it runs, where it lives, and whether Rocket may
 * import it. Only MySQL and PostgreSQL on one of your own servers qualify; ClickHouse, Tinybird,
 * PlanetScale, RDS and every other external database stay out.
 */
class Databases
{
    private const array IMPORTABLE_DRIVERS = [
        'mysql_native' => 'mysql',
        'pgsql_native' => 'pgsql',
    ];

    private const array IMPORTABLE_TYPES = [
        'mysql' => 'mysql',
        'postgresql' => 'pgsql',
    ];

    private const array LABELS = [
        'mysql_native' => 'MySQL',
        'pgsql_native' => 'PostgreSQL',
        'clickhouse_native' => 'ClickHouse',
        'planetscale' => 'PlanetScale',
        'aws_rds' => 'AWS RDS',
        'tinybird' => 'Tinybird',
        'mysql' => 'MySQL',
        'postgresql' => 'PostgreSQL',
        'clickhouse' => 'ClickHouse',
    ];

    /** @param array<string, mixed> $database */
    public static function engine(array $database): ?string
    {
        $driver = $database['driver'] ?? null;

        if (is_string($driver)) {
            return self::IMPORTABLE_DRIVERS[$driver] ?? null;
        }

        $onOwnServer = ($database['is_server_database'] ?? false) && ! ($database['is_provider_database'] ?? false);

        return $onOwnServer ? (self::IMPORTABLE_TYPES[$database['type'] ?? ''] ?? null) : null;
    }

    /** @param array<string, mixed> $database */
    public static function isImportable(array $database): bool
    {
        return self::engine($database) !== null && self::host($database) !== null;
    }

    /** @param array<string, mixed> $database */
    public static function host(array $database): ?string
    {
        return is_array($database['server'] ?? null) ? Servers::host($database['server']) : null;
    }

    /** @param array<string, mixed> $database */
    public static function label(array $database): string
    {
        $key = $database['driver'] ?? $database['type'] ?? $database['kind'] ?? null;

        return self::LABELS[$key] ?? (is_string($key) ? $key : 'unknown');
    }

    /** @param array<string, mixed> $database */
    public static function display(array $database): string
    {
        $server = $database['server']['name'] ?? null;
        $host = self::host($database);

        return $database['name'].' · '.self::label($database).($server === null ? '' : " · on {$server}".($host === null ? '' : " ({$host})"));
    }
}
