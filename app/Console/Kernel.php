<?php

namespace App\Console;

use LaravelZero\Framework\Kernel as BaseKernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Exception\NamespaceNotFoundException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

use function Laravel\Prompts\confirm;

/**
 * Laravel Zero's kernel, except that a mistyped command is not handed to the default command as an argument: it is
 * corrected when there is one likely command and someone is there to say yes (saying no just stops), and explained
 * otherwise. Mistakes in arguments and options are explained by the InputErrorRenderer instead of Symfony's red block.
 */
class Kernel extends BaseKernel
{
    private ?InputInterface $input = null;

    public function handle($input, $output = null)
    {
        $this->input = $input;

        try {
            $this->bootstrap();
            $corrected = $this->withKnownCommand($input);
        } catch (CommandNotFoundException $exception) {
            $this->renderException($output, $exception);

            return 1;
        }

        if ($corrected === null) {
            return 1;
        }

        $this->input = $corrected;

        return parent::handle($corrected, $output);
    }

    protected function ensureDefaultCommand($input): void
    {
        $this->bootstrap();
    }

    protected function renderException($output, Throwable $e)
    {
        if ($e instanceof ExceptionInterface && $this->input !== null && $output !== null) {
            (new InputErrorRenderer($this->getArtisan()))->render($output, $this->input, $e);

            return;
        }

        parent::renderException($output, $e);
    }

    private function withKnownCommand(InputInterface $input): ?InputInterface
    {
        $name = $input->getFirstArgument();

        if ($name === null) {
            return $input;
        }

        try {
            $this->getArtisan()->find((string) $name);

            return $input;
        } catch (CommandNotFoundException $exception) {
            $exception = $this->inNamespace((string) $name) ?? $exception;
            $alternatives = $exception->getAlternatives();

            if (count($alternatives) !== 1 || ! $input instanceof ArgvInput || ! $this->canAsk($input)) {
                throw $exception;
            }

            return confirm(label: "Rocket has no `{$name}` command. Did you mean `rocket {$alternatives[0]}`?", default: true)
                ? $this->replaceCommand($input, (string) $name, $alternatives[0])
                : null;
        }
    }

    private function inNamespace(string $name): ?CommandNotFoundException
    {
        try {
            $namespace = $this->getArtisan()->findNamespace($name);
        } catch (NamespaceNotFoundException) {
            return null;
        }

        $commands = collect($this->getArtisan()->all($namespace))
            ->reject(fn (Command $command): bool => $command->isHidden())
            ->keys()
            ->values()
            ->all();

        return new CommandNotFoundException("Command \"{$name}\" is not defined.", $commands);
    }

    private function canAsk(InputInterface $input): bool
    {
        return ! $input->hasParameterOption(['--json', '--no-interaction', '-n'], true)
            && ($this->app->runningUnitTests() || (defined('STDIN') && stream_isatty(STDIN)));
    }

    private function replaceCommand(ArgvInput $input, string $typed, string $command): ArgvInput
    {
        $tokens = $input->getRawTokens();
        $position = array_search($typed, $tokens, true);

        if ($position !== false) {
            $tokens[$position] = $command;
        }

        return new ArgvInput(['rocket', ...$tokens]);
    }
}
