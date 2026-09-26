<?php

namespace App\Actions;

use App\Support\Teams;
use Lorisleiva\Actions\Concerns\AsAction;

class SaveApiToken
{
    use AsAction;

    public function handle(string $token): void
    {
        (new SaveSettings)(['API_TOKEN' => $token]);

        app(Teams::class)->forget();
    }
}
