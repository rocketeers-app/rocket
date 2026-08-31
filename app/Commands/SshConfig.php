<?php

namespace App\Commands;

use App\Actions\GetCurrentSshConfig;
use App\Actions\SetApiToken;
use App\Commands\Concerns\WithSteps;
use App\Exceptions\StepException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SshConfig extends Command
{
    use WithSteps;

    protected $signature = 'ssh:config';

    protected $description = 'Update your local SSH config with all sites and servers';

    public function handle()
    {
        return $this->runWithSteps(function () {
            (new SetApiToken)($this);

            $this->startProgress(2);

            $sshConfig = $this->step('Fetching SSH config', fn () => (string) Http::timeout(5)
                ->withoutVerifying()
                ->withHeaders([
                    'Authorization' => 'Bearer '.env('API_TOKEN'),
                ])
                ->get('https://rocketeers.app/api/v1/ssh/config'));

            $this->step('Updating local SSH config', function () use ($sshConfig) {
                $delimiter = '### ROCKETEERS APP ###';
                $currentSshConfig = (new GetCurrentSshConfig)();

                if (str_contains(trim($currentSshConfig), $delimiter)) {
                    $newSshConfig = preg_replace_callback(
                        '/'.preg_quote($delimiter, '/').'.*'.preg_quote($delimiter, '/').'/ims',
                        fn ($matches) => $delimiter.PHP_EOL.PHP_EOL.$sshConfig.PHP_EOL.PHP_EOL.$delimiter,
                        $currentSshConfig
                    );
                } else {
                    $newSshConfig = trim($currentSshConfig.PHP_EOL.PHP_EOL.$delimiter.PHP_EOL.PHP_EOL.$sshConfig.PHP_EOL.PHP_EOL.$delimiter);
                }

                $sshDir = getenv('HOME').'/.ssh';

                if (! is_dir($sshDir)) {
                    mkdir($sshDir, 0700, true);
                }

                if (file_put_contents($sshDir.'/config', $newSshConfig.PHP_EOL) === false) {
                    throw new StepException('Could not write to '.$sshDir.'/config');
                }

                chmod($sshDir.'/config', 0600);
            });

            $this->finishProgress();
        });
    }
}
