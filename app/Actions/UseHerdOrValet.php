<?php

namespace App\Actions;

use Exception;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

class UseHerdOrValet
{
    use AsAction;

    public function handle()
    {
        $process = Process::fromShellCommandline('(type herd > /dev/null && echo "herd") || (type valet > /dev/null && echo "valet")');
        $process->run();

        $output = trim($process->getOutput());

        if (str_contains($output, 'herd')) {
            return 'herd';
        } elseif (str_contains($output, 'valet')) {
            return 'valet';
        }

        throw new Exception('No Herd or Valet found');
    }
}
