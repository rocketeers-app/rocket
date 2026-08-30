<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

class GetCurrentSshConfig
{
    use AsAction;

    public function handle(): string
    {
        $path = getenv('HOME').'/.ssh/config';

        if (! is_file($path)) {
            return '';
        }

        return trim(file_get_contents($path));
    }
}
