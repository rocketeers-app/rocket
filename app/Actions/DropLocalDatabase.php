<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class DropLocalDatabase
{
    use AsAction;

    public function handle(string $name): void
    {
        $process = Process::fromShellCommandline(
            'mysql -u root --password= -e '.escapeshellarg('DROP DATABASE IF EXISTS `'.$this->quoteMysqlIdentifier($name).'`').' 2>/dev/null'
        );
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException('Could not drop local database: '.trim($process->getErrorOutput()));
        }
    }

    protected function quoteMysqlIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }
}
