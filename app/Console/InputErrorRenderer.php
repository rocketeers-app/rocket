<?php

namespace App\Console;

use Illuminate\Console\View\Components\Error;
use Illuminate\Support\Str;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Explains a mistyped command, argument or option in one plain sentence with what to type instead, in the
 * same style as every other error, or as `{"error": {...}}` under --json.
 */
class InputErrorRenderer
{
    private const int MAX_ALTERNATIVES = 5;

    public function __construct(private readonly Application $artisan) {}

    public function render(OutputInterface $output, InputInterface $input, ExceptionInterface $exception): void
    {
        $command = $exception instanceof CommandNotFoundException ? null : $this->command($input);
        $alternatives = $exception instanceof CommandNotFoundException ? $exception->getAlternatives() : [];
        $message = $exception instanceof CommandNotFoundException
            ? $this->unknownCommand($input, $exception)
            : $this->message($exception, $command);

        if ($input->hasParameterOption('--json', true)) {
            $output->writeln((string) json_encode(['error' => array_filter([
                'status' => $exception instanceof CommandNotFoundException ? 404 : 422,
                'message' => strip_tags($message),
                'alternatives' => $alternatives === [] ? null : $alternatives,
                'usage' => $command === null ? null : 'rocket '.$command->getSynopsis(true),
            ])], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return;
        }

        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        (new Error($errors))->render($message);

        foreach ($this->hints($command, $alternatives, $exception) as $hint) {
            $errors->writeln("  {$hint}");
        }

        $errors->writeln('');
    }

    private function unknownCommand(InputInterface $input, CommandNotFoundException $exception): string
    {
        $name = (string) $input->getFirstArgument();

        return str_contains($exception->getMessage(), 'is ambiguous')
            ? "`{$name}` could be several commands."
            : "Rocket has no `{$name}` command.";
    }

    private function message(ExceptionInterface $exception, ?Command $command): string
    {
        $message = $exception->getMessage();
        $rocket = $command === null ? 'rocket' : "rocket {$command->getName()}";

        return match (true) {
            preg_match('/^The "(-{1,2}[^"]+)" option does not exist\.$/', $message, $match) === 1 => "`{$rocket}` has no `{$match[1]}` option.".$this->closestOption($command, $match[1]),
            preg_match('/^The "--([^"]+)" option requires a value\.$/', $message, $match) === 1 => "`--{$match[1]}` needs a value: `--{$match[1]}=…`.",
            preg_match('/^The "--([^"]+)" option does not accept a value\.$/', $message, $match) === 1 => "`--{$match[1]}` takes no value; pass just `--{$match[1]}`.",
            preg_match('/^Not enough arguments \(missing: "([^"]+)"\)\.$/', $message, $match) === 1 => 'Missing '.$this->arguments(explode(', ', $match[1])).'.',
            preg_match('/^No arguments expected(?: for "[^"]+" command)?, got "([^"]*)"\.$/', $message, $match) === 1 => "`{$rocket}` takes no arguments, but got `{$match[1]}`.",
            preg_match('/^Too many arguments(?: to "[^"]+" command)?, expected arguments "(.+)"\.$/', $message, $match) === 1 => "Too many arguments: `{$rocket}` takes ".$this->arguments(array_values(array_diff(explode('" "', $match[1]), ['command']))).'.',
            default => $message,
        };
    }

    private function arguments(array $names): string
    {
        $arguments = array_map(fn (string $name): string => "<{$name}>", $names);

        return $arguments === [] ? 'no arguments' : implode(' ', $arguments);
    }

    private function closestOption(?Command $command, string $typed): string
    {
        if ($command === null) {
            return '';
        }

        $needle = ltrim($typed, '-');
        $closest = collect($command->getDefinition()->getOptions())
            ->keys()
            ->map(fn (string $name): array => ['name' => $name, 'distance' => levenshtein($needle, $name)])
            ->filter(fn (array $option): bool => $option['distance'] <= max(1, intdiv(strlen($needle), 3)) || Str::startsWith($option['name'], $needle))
            ->sortBy('distance')
            ->first();

        return $closest === null ? '' : " Did you mean `--{$closest['name']}`?";
    }

    private function hints(?Command $command, array $alternatives, ExceptionInterface $exception): array
    {
        if ($exception instanceof CommandNotFoundException) {
            return match (count($alternatives)) {
                0 => ['Run <fg=cyan>rocket</> to see every command.'],
                1 => ["Did you mean <fg=cyan>rocket {$alternatives[0]}</>?"],
                default => [
                    'Did you mean one of these?',
                    ...array_map(fn (string $alternative): string => "  <fg=gray>•</> <fg=cyan>rocket {$alternative}</>", array_slice($alternatives, 0, self::MAX_ALTERNATIVES)),
                    ...(count($alternatives) > self::MAX_ALTERNATIVES ? ['  <fg=gray>… and '.(count($alternatives) - self::MAX_ALTERNATIVES).' more; run</> <fg=cyan>rocket</> <fg=gray>to see every command.</>'] : []),
                ],
            };
        }

        if ($command === null) {
            return ['Run <fg=cyan>rocket --help</> to see how to use Rocket.'];
        }

        return [
            '<fg=gray>Usage:</> rocket '.$command->getSynopsis(true),
            "<fg=gray>Run</> <fg=cyan>rocket {$command->getName()} --help</> <fg=gray>to see every argument and option.</>",
        ];
    }

    private function command(InputInterface $input): ?Command
    {
        $name = $input->getFirstArgument();

        if ($name === null) {
            return null;
        }

        try {
            return $this->artisan->find((string) $name);
        } catch (CommandNotFoundException) {
            return null;
        }
    }
}
