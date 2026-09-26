<?php

namespace App\Support;

use App\Schema\Field;
use App\Schema\Operation;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;

/** Asks for one field with the prompt its type calls for; a relation becomes a searchable select. */
class FieldPrompter
{
    public function __construct(private readonly RecordPicker $picker) {}

    /** @param array<string, string> $pathValues */
    public function prompt(Field $field, ?Operation $relationList, array $pathValues, mixed $current = null): mixed
    {
        $label = $field->label();
        $hint = $field->hint();
        $required = $field->required && ! $field->nullable;

        if ($relationList !== null) {
            return $field->isArray()
                ? $this->picker->pickMany($relationList, $pathValues, $label, $required)
                : $this->picker->pick($relationList, $pathValues, $label, $required);
        }

        if ($field->enum !== null) {
            $options = collect($field->enum)->mapWithKeys(fn (mixed $value): array => [(string) $value => (string) $value])->all();

            return FieldValue::cast($field, select(label: $label, options: $options, default: $current === null ? null : (string) $current, hint: $hint));
        }

        if ($field->isArray() && $field->itemsEnum !== null) {
            $options = collect($field->itemsEnum)->mapWithKeys(fn (mixed $value): array => [(string) $value => (string) $value])->all();

            return FieldValue::cast($field, multiselect(label: $label, options: $options, default: array_map('strval', (array) $current), required: $required, hint: $hint));
        }

        return match (true) {
            $field->type === 'boolean' => confirm(label: $label, default: (bool) ($current ?? $field->default ?? false), hint: $hint),
            in_array($field->type, ['integer', 'number'], true) => FieldValue::cast($field, text(
                label: $label,
                default: $current === null ? (string) ($field->default ?? '') : (string) $current,
                required: $required,
                validate: fn (string $value): ?string => $this->validateNumber($field, $value),
                hint: $hint,
            )),
            $field->isArray() && $field->itemsType !== 'object' => FieldValue::cast($field, text(
                label: $label,
                placeholder: 'Comma-separated',
                default: implode(',', array_map('strval', (array) $current)),
                required: $required,
                hint: $hint,
            )),
            $field->isArray() || in_array($field->type, ['object', 'mixed'], true) => FieldValue::cast($field, textarea(
                label: $label.' (JSON)',
                default: $current === null ? '' : (string) json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                required: $required,
                validate: fn (string $value): ?string => $value === '' || json_decode($value) !== null ? null : 'This is not valid JSON.',
                hint: $hint,
            )),
            $field->isSecret() => password(label: $label, required: $required, hint: $hint) ?: null,
            default => text(
                label: $label,
                default: (string) ($current ?? $field->default ?? ''),
                required: $required,
                validate: fn (string $value): ?string => $field->maxLength !== null && mb_strlen($value) > $field->maxLength ? "At most {$field->maxLength} characters." : null,
                hint: $hint,
            ) ?: null,
        };
    }

    private function validateNumber(Field $field, string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return 'Enter a number.';
        }

        return match (true) {
            $field->minimum !== null && $value < $field->minimum => "At least {$field->minimum}.",
            $field->maximum !== null && $value > $field->maximum => "At most {$field->maximum}.",
            default => null,
        };
    }
}
