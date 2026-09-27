<?php

namespace App\Commands;

use App\Actions\GetCurrentSshConfig;
use App\Actions\SendApiRequest;
use App\Api\Requests\GetSshConfig;
use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class SshConfig extends Command
{
    use OutputsJson;
    use WithSteps;

    protected $signature = 'ssh:config';

    protected $description = 'Update your local SSH config with all sites and servers';

    public function handle(): int
    {

        $sshConfig = $this->step('Fetching SSH config', fn () => (new SendApiRequest)(new GetSshConfig)->body());

        $this->step('Updating local SSH config', function () use ($sshConfig) {
            $delimiter = '### ROCKETEERS APP ###';
            $currentSshConfig = (new GetCurrentSshConfig)();

            if (str_contains(trim((string) $currentSshConfig), $delimiter)) {
                $newSshConfig = preg_replace_callback('/'.$delimiter.'.*'.$delimiter.'/im', fn ($matches) => $delimiter.PHP_EOL.PHP_EOL.$sshConfig.PHP_EOL.PHP_EOL.$delimiter, (string) $currentSshConfig);

                $process = Process::fromShellCommandline('echo "'.$newSshConfig.'" > ~/.ssh/config');
            } else {
                $process = Process::fromShellCommandline('echo "'.$delimiter.PHP_EOL.PHP_EOL.$sshConfig.PHP_EOL.PHP_EOL.$delimiter.'" > ~/.ssh/config');
            }

            $process->setTimeout(300);
            $process->run();
        });

        return $this->wantsJson() ? $this->emitJson(['config' => $sshConfig]) : self::SUCCESS;
    }
}
