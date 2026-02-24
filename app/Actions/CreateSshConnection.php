<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Ssh\Ssh;

class CreateSshConnection
{
    use AsAction;

    public function handle(string $server): Ssh
    {
        return Ssh::create('rocketeer', $server)
            ->disableStrictHostKeyChecking()
            ->addExtraOption('-o LogLevel=ERROR');
    }
}
