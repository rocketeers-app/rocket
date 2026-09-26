<?php

namespace App\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/** Shows an API answer to a person — a table for a list, label and value rows for a record — or as raw JSON. */
class OutputRenderer
{
    private const array PREFERRED_COLUMNS = ['status', 'type', 'ip', 'ipv4', 'ip_address', 'fqdn', 'region', 'size', 'server_type', 'php_version', 'branch'];

    private const int EXTRA_COLUMNS = 3;

    public function json(Command $command, mixed $data): void
    {
        $command->getOutput()->writeln(
            (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            OutputInterface::OUTPUT_RAW,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<string, mixed>  $body
     */
    public function table(Command $command, array $records, array $body = []): void
    {
        if ($records === []) {
            $command->getOutput()->writeln('  <fg=gray>Nothing here yet.</>');

            return;
        }

        $columns = $this->columns($records);

        $command->table(
            array_map(fn (string $column): string => Str::headline($column), $columns),
            array_map(fn (array $record): array => array_map(fn (string $column): string => $this->cell($record[$column] ?? null), $columns), $records),
        );

        $footer = $this->footer($body, count($records));

        if ($footer !== null) {
            $command->getOutput()->writeln("  <fg=gray>{$footer}</>");
        }
    }

    /** @param array<string, mixed> $record */
    public function detail(Command $command, array $record): void
    {
        foreach ($record as $key => $value) {
            $command->outputComponents()->twoColumnDetail(Str::headline((string) $key), $this->cell($value, long: true));
        }
    }

    /** @param array<string, mixed> $body */
    public function footer(array $body, int $count): ?string
    {
        $current = $body['meta']['current_page'] ?? $body['current_page'] ?? null;
        $last = $body['meta']['last_page'] ?? $body['last_page'] ?? null;
        $total = $body['meta']['total'] ?? $body['total'] ?? null;

        if ($current === null || $last === null) {
            return null;
        }

        $parts = ["Page {$current}/{$last}", ($total ?? $count).' total'];

        if ($current < $last) {
            $parts[] = '--page='.($current + 1);
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, string>
     */
    private function columns(array $records): array
    {
        $first = $records[0];
        $labelKey = Records::labelKey($first);
        $scalar = array_keys(array_filter($first, fn (mixed $value): bool => is_scalar($value) || $value === null));

        $extras = collect(self::PREFERRED_COLUMNS)
            ->filter(fn (string $column): bool => in_array($column, $scalar, true) && $column !== $labelKey)
            ->merge(collect($scalar)->reject(fn (string $column): bool => in_array($column, ['id', 'uuid', $labelKey], true) || str_ends_with($column, '_id') || str_ends_with($column, '_at')))
            ->unique()
            ->take(self::EXTRA_COLUMNS)
            ->all();

        return array_values(array_filter([$labelKey, ...$extras, in_array('created_at', $scalar, true) ? 'created_at' : null]));
    }

    private function cell(mixed $value, bool $long = false): string
    {
        return match (true) {
            $value === null || $value === '' => '<fg=gray>—</>',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) && array_is_list($value) => $value === [] ? '<fg=gray>none</>' : $this->listSummary($value),
            is_array($value) => OutputFormatter::escape(Records::labelKey($value) !== null ? Records::display($value) : Str::limit((string) json_encode($value, JSON_UNESCAPED_SLASHES), 80)),
            default => $this->scalar((string) $value, $long),
        };
    }

    /** @param array<int, mixed> $value */
    private function listSummary(array $value): string
    {
        if (collect($value)->every(fn (mixed $item): bool => is_scalar($item))) {
            return OutputFormatter::escape(Str::limit(implode(', ', array_map('strval', $value)), 80));
        }

        $labels = collect($value)->filter(fn (mixed $item): bool => is_array($item))->map(fn (array $item): string => Records::label($item));

        return OutputFormatter::escape(Str::limit($labels->take(3)->implode(', ').($labels->count() > 3 ? ' +'.($labels->count() - 3).' more' : ''), 80));
    }

    private function scalar(string $value, bool $long): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value) === 1) {
            return str_replace('T', ' ', substr($value, 0, 16));
        }

        $value = str_replace(["\r\n", "\n"], ' ', $value);

        return OutputFormatter::escape($long ? Str::limit($value, 200) : Str::limit($value, 48));
    }
}
