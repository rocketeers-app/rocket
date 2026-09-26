<?php

namespace App\Api;

use App\Exceptions\ApiException;
use App\Support\PermissionGate;
use Saloon\Http\Response;

/** Turns an API refusal into one plain sentence, keeping status, permission and field errors for --json. */
class ApiErrorPresenter
{
    public const SETUP_HINT = 'Create a Rocket CLI token in Rocketeers under Settings, API, and run the `rocket setup-token` command it shows.';

    public static function fromResponse(Response $response, ?string $teamName = null): ApiException
    {
        $status = $response->status();
        $body = self::json($response);
        $message = is_string($body['message'] ?? null) ? $body['message'] : null;

        return match (true) {
            $status === 401 => new ApiException('This token is not valid. '.self::SETUP_HINT, $status),
            $status === 403 && ($body['code'] ?? null) === 'insufficient_permission' => self::denied($body, $teamName),
            $status === 403 => new ApiException($message ?: 'Rocketeers refused this request.', $status),
            $status === 404 => new ApiException('Not found'.($teamName ? " in team {$teamName}" : '').'. Check the name or id.', $status),
            $status === 422 => new ApiException($message ?: 'Some fields are not valid.', $status, errors: self::errors($body)),
            $status === 429 => new ApiException('Too many requests. Try again in a minute.', $status),
            $status >= 500 => new ApiException("Rocketeers answered with HTTP {$status}. Try again later.", $status),
            default => new ApiException($message ?: "Rocketeers answered with HTTP {$status}.", $status),
        };
    }

    public static function unreachable(): ApiException
    {
        return new ApiException('Could not reach Rocketeers at '.config('rocketeers.api_url').'.');
    }

    public static function missingToken(): ApiException
    {
        return new ApiException('No Rocketeers token configured. '.self::SETUP_HINT, 401);
    }

    /** @param array<string, mixed> $body */
    private static function denied(array $body, ?string $teamName): ApiException
    {
        $permission = $body['missing_permissions'][0] ?? null;

        if (! is_string($permission)) {
            return new ApiException($body['message'] ?? 'Your role in this team does not allow this.', 403);
        }

        return app(PermissionGate::class)->denial($permission, $teamName);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, array<int, string>>
     */
    private static function errors(array $body): array
    {
        return collect($body['errors'] ?? [])
            ->map(fn (mixed $messages): array => array_values(array_map('strval', (array) $messages)))
            ->all();
    }

    /** @return array<string, mixed> */
    private static function json(Response $response): array
    {
        try {
            $body = $response->json();
        } catch (\Throwable) {
            return [];
        }

        return is_array($body) ? $body : [];
    }
}
