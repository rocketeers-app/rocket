<?php

namespace App\Actions;

use App\Api\Requests\GetMe;
use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;

class FetchCurrentUser
{
    use AsAction;

    public function handle(?string $token = null): array
    {
        $json = (new SendApiRequest)(new GetMe, $token)->json();
        $user = $json['data'] ?? $json;

        if (! is_array($user) || blank($user['email'] ?? null)) {
            throw new StepException('Rocketeers answered with something other than your account. Check API_URL in ~/.rocketeers/.env.');
        }

        return $user;
    }
}
