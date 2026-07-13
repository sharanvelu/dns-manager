<?php

namespace App\Http\Requests;

use App\Connectors\ConnectorRegistry;
use App\Enums\ProviderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProviderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(ProviderType::class)],
            'enabled' => ['boolean'],
            'managed_record_types' => ['required', 'array', 'min:1'],
            'managed_record_types.*' => ['string'],
            'config' => ['required', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = (string) $this->input('type');

            try {
                $class = app(ConnectorRegistry::class)->classFor($type);
            } catch (\InvalidArgumentException) {
                return; // base rule already reports the invalid type
            }

            $supported = $class::supportedRecordTypes();

            foreach ((array) $this->input('managed_record_types', []) as $recordType) {
                if (! in_array($recordType, $supported, true)) {
                    $validator->errors()->add(
                        'managed_record_types',
                        "{$class::displayName()} does not support {$recordType} records.",
                    );
                }
            }

            $config = (array) $this->input('config', []);
            $isUpdate = $this->route('provider') !== null;

            foreach ($class::configSchema() as $field) {
                $value = $config[$field->key] ?? null;

                // Secrets may be omitted on update to keep the stored value.
                $mayOmit = $isUpdate && $field->secret;

                if ($field->required && ! $mayOmit && ($value === null || $value === '')) {
                    $validator->errors()->add(
                        "config.{$field->key}",
                        "{$field->label} is required.",
                    );
                }
            }
        });
    }
}
