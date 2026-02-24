<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Ssh\Ssh;

class IsWordPress
{
    use AsAction;

    public function handle($site, $server): bool
    {
        $process = Ssh::create('rocketeer', $server)
            ->disableStrictHostKeyChecking()
            ->execute([
                'test -f /var/www/'.$site.'/current/wp-config.php -o -f /var/www/'.$site.'/current/public/wp-config.php && echo "yes" || echo "no"',
            ]);

        return trim($process->getOutput()) === 'yes';
    }
}
