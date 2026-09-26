<?php

namespace App\Actions;

use App\Support\LoggedSsh;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateSshConnection
{
    use AsAction;

    public function handle(string $server, string $user = 'rocketeer'): LoggedSsh
    {
        return LoggedSsh::create($user, $server)
            ->disableStrictHostKeyChecking()
            ->addExtraOption('-o LogLevel=ERROR');
    }
}
