<?php

namespace App\Http\Requests;

use App\Enums\RecordType;
use App\Support\DnsEntryRules;
use Illuminate\Foundation\Http\FormRequest;

class DnsEntryRequest extends FormRequest
{
    public function rules(): array
    {
        $type = RecordType::tryFrom((string) $this->input('type'));

        return [
            ...DnsEntryRules::rules($type),
            // Explicit provider targeting; omitted = all compatible enabled providers.
            'providers' => ['nullable', 'array'],
            'providers.*' => ['integer', 'exists:providers,id'],
        ];
    }

    public function messages(): array
    {
        return DnsEntryRules::messages();
    }
}
