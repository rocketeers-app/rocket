<?php

namespace App\Exceptions;

/** A refusal or failure from the Rocketeers API, carrying what a script needs to react to it. */
class ApiException extends StepException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $permission = null,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['error' => array_filter([
            'status' => $this->status,
            'message' => $this->getMessage(),
            'permission' => $this->permission,
            'errors' => $this->errors === [] ? null : $this->errors,
        ], fn (mixed $value): bool => $value !== null)];
    }
}
