<?php

declare(strict_types = 1);

namespace App\Http\Requests;

use App\Models\Provider;
use App\Models\ZoneProvider;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use App\Connectors\ConnectorRegistry;
use Illuminate\Foundation\Http\FormRequest;

class ZoneProviderRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'enabled' => ['boolean'],
            'config' => ['array'],
        ];

        if (! $this->isUpdate()) {
            $rules['provider_id'] = [
                'required',
                'integer',
                'exists:providers,id',
                Rule::unique('zone_providers', 'provider_id')
                    ->where('dns_zone_id', $this->route('zone')->id),
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Required zone fields are only enforced on update: on store the
            // controller runs zone discovery first to fill in the blanks.
            if (! $this->isUpdate() || ! $this->has('config')) {
                return;
            }

            $provider = $this->targetProvider();

            if ($provider === null) {
                return;
            }

            $class = app(ConnectorRegistry::class)->classFor($provider->type->value);
            $config = (array) $this->input('config', []);

            foreach ($class::zoneConfigSchema() as $field) {
                $value = $config[$field->key] ?? null;

                // Secrets may be omitted on update to keep the stored value.
                if ($field->required && ! $field->secret && ($value === null || $value === '')) {
                    $validator->errors()->add(
                        "config.{$field->key}",
                        "{$field->label} is required.",
                    );
                }
            }
        });
    }

    protected function isUpdate(): bool
    {
        return $this->route('zoneProvider') !== null;
    }

    protected function targetProvider(): ?Provider
    {
        $zoneProvider = $this->route('zoneProvider');

        if ($zoneProvider instanceof ZoneProvider) {
            return $zoneProvider->provider;
        }

        return Provider::find($this->input('provider_id'));
    }
}
