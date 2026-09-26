<?php

namespace App\Actions;

use App\Exceptions\ApiException;
use App\Support\Databases;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

use function Laravel\Prompts\select;

/**
 * Picks which of an environment's importable databases to import: --all, --database, the only one, or a
 * pick when someone is there to answer. An install also needs its main database, the one DB_CONNECTION
 * points at: the one named by --database or like the remote DB_DATABASE, else a second pick.
 */
class ChooseDatabases
{
    use AsAction;

    public function handle(Collection $importable, bool $interactive, ?string $database, bool $all): Collection
    {
        if ($all) {
            return $importable;
        }

        if (filled($database)) {
            return collect([$this->named($importable, $database)]);
        }

        if ($importable->count() === 1) {
            return $importable;
        }

        if (! $interactive) {
            throw new ApiException('This environment has several databases: '.$importable->pluck('name')->implode(', ').'. Pass --database=<name> or --all.', 409);
        }

        return collect([$this->pick($importable, 'Which database do you want to import?', null, 'Use --all to import every one of them')]);
    }

    public function forInstall(Collection $importable, bool $interactive, ?string $database, bool $all, ?string $remoteDatabase): array
    {
        if ($importable->isEmpty()) {
            return ['databases' => collect(), 'main' => null];
        }

        if ($all) {
            return ['databases' => $importable, 'main' => $this->main($importable, $interactive, $database, $remoteDatabase)];
        }

        if (filled($database) || $importable->count() === 1) {
            $chosen = $this->handle($importable, $interactive, $database, false);

            return ['databases' => $chosen, 'main' => $chosen->first()];
        }

        if (! $interactive) {
            $match = $importable->firstWhere('name', $remoteDatabase)
                ?? throw new ApiException('This environment has several databases: '.$importable->pluck('name')->implode(', ').', and none is its DB_DATABASE. Pass --database=<name> or --all.', 409);

            return ['databases' => collect([$match]), 'main' => $match];
        }

        $scope = select(
            label: 'Which databases do you want to import?',
            options: ['one' => 'Only one', 'all' => "All {$importable->count()}"],
        );

        if ($scope === 'all') {
            return ['databases' => $importable, 'main' => $this->main($importable, true, null, $remoteDatabase)];
        }

        $chosen = $this->pick($importable, 'Which database do you want to import?', $remoteDatabase);

        return ['databases' => collect([$chosen]), 'main' => $chosen];
    }

    private function main(Collection $databases, bool $interactive, ?string $database, ?string $remoteDatabase): array
    {
        if (filled($database)) {
            return $this->named($databases, $database);
        }

        if ($databases->count() === 1) {
            return $databases->first();
        }

        if ($interactive) {
            return $this->pick($databases, 'Which one is the main connection (DB_CONNECTION)?', $remoteDatabase);
        }

        return $databases->firstWhere('name', $remoteDatabase)
            ?? throw new ApiException('None of '.$databases->pluck('name')->implode(', ').' is the DB_DATABASE of this environment. Pass --database=<name> with --all to choose the main connection.', 409);
    }

    private function named(Collection $importable, string $database): array
    {
        return $importable->firstWhere('name', $database)
            ?? throw new ApiException("No importable database `{$database}`. Choose from: ".$importable->pluck('name')->implode(', ').'.', 404);
    }

    private function pick(Collection $databases, string $label, ?string $preferred, string $hint = ''): array
    {
        $databases = $databases->values();
        $default = $databases->search(fn (array $database): bool => $database['name'] === $preferred);

        $index = select(
            label: $label,
            options: $databases->mapWithKeys(fn (array $database, int $index): array => ["#{$index}" => Databases::display($database)])->all(),
            default: $default === false ? null : "#{$default}",
            hint: $hint,
        );

        return $databases[(int) ltrim((string) $index, '#')];
    }
}
