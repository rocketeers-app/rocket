<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

class IsWordPress
{
    use AsAction;

    public function handle($site, $server): bool
    {
        $process = (new CreateSshConnection)($server)
            ->execute([
                'test -f /var/www/'.$site.'/current/wp-config.php -o -f /var/www/'.$site.'/current/public/wp-config.php -o -f /var/www/'.$site.'/current/config/application.php && echo "yes" || echo "no"',
            ]);

        return trim($process->getOutput()) === 'yes';
    }
}
