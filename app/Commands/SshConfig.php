<?php

namespace App\Commands;

use App\Actions\GetCurrentSshConfig;
use App\Actions\SetApiToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class SshConfig extends Command
{
    protected $signature = 'ssh:config';
    protected $description = 'Update your local SSH config with all sites and servers';

    public function handle()
    {
        (new SetApiToken)($this);

        $sshConfig = (string) Http::timeout(3)
            ->withoutVerifying()
            ->withHeaders([
                'Authorization' => 'Bearer '.env('API_TOKEN'),
            ])
            ->get('https://rocketeers-app.test/api/v1/ssh/config');

        $delimiter = '### ROCKETEERS APP ###';

        $currentSshConfig = (new GetCurrentSshConfig)();

        if (str_contains(trim((string) $currentSshConfig), $delimiter)) {
            $newSshConfig = preg_replace_callback('/'.$delimiter.'.*'.$delimiter.'/im', fn($matches) => $delimiter.PHP_EOL.PHP_EOL.$sshConfig.PHP_EOL.PHP_EOL.$delimiter, (string) $currentSshConfig);

            $process = Process::fromShellCommandline('echo "'.$newSshConfig.'" > ~/.ssh/config');
        } else {
            $process = Process::fromShellCommandline('echo "'.$delimiter.PHP_EOL.PHP_EOL.$sshConfig.PHP_EOL.PHP_EOL.$delimiter.'" > ~/.ssh/config');
        }

        $process->setTty(Process::isTtySupported());
        $process->setTimeout(300);
        $process->run();
    }
}
