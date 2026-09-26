<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Ssh\Ssh;

class CreateSshConnection
{
    use AsAction;

    public function handle(string $server, string $user = 'rocketeer'): Ssh
    {
        return Ssh::create($user, $server)
            ->disableStrictHostKeyChecking()
            ->addExtraOption('-o LogLevel=ERROR');
    }
}
