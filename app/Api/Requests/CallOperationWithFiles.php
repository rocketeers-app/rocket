<?php

namespace App\Api\Requests;

use App\Schema\Field;
use App\Schema\Operation;
use Saloon\Contracts\Body\HasBody;
use Saloon\Data\MultipartValue;
use Saloon\Traits\Body\HasMultipartBody;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * An operation whose body carries files, sent as multipart/form-data: each file field holds local paths that are
 * streamed as they are read, the other fields go as form values. Uploads get longer than a JSON request to finish.
 */
class CallOperationWithFiles extends CallOperation implements HasBody
{
    use HasMultipartBody;
    use HasTimeout;

    protected int $requestTimeout = 900;

    public function __construct(Operation $operation, array $pathValues, array $queryValues, public readonly array $payload)
    {
        parent::__construct($operation, $pathValues, $queryValues);
    }

    protected function defaultBody(): array
    {
        $files = collect($this->operation->bodyFields)->filter(fn (Field $field): bool => $field->isFile())->keyBy('name');
        $values = [];

        foreach ($this->payload as $name => $value) {
            $field = $files->get($name);

            if ($field instanceof Field) {
                foreach ((array) $value as $path) {
                    $values[] = new MultipartValue($field->isArray() ? "{$name}[]" : $name, fopen((string) $path, 'rb'), basename((string) $path));
                }

                continue;
            }

            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    $values[] = new MultipartValue("{$name}[]", $this->formValue($item));
                }

                continue;
            }

            $values[] = new MultipartValue($name, $this->formValue($value));
        }

        return $values;
    }

    private function formValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            $value === null => '',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
        };
    }
}
