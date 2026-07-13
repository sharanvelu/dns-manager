<?php

namespace App\Http\Requests;

use App\Enums\RecordType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DnsEntryRequest extends FormRequest
{
    public function rules(): array
    {
        $type = RecordType::tryFrom((string) $this->input('type'));

        return [
            'name' => [
                'required',
                'string',
                'max:253',
                'regex:/^(\*\.)?([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/',
            ],
            'type' => ['required', Rule::enum(RecordType::class)],
            'content' => array_merge(
                ['required', 'string', 'max:2048'],
                match ($type) {
                    RecordType::A => ['ipv4'],
                    RecordType::AAAA => ['ipv6'],
                    default => [],
                },
            ),
            'ttl' => ['nullable', 'integer', 'min:60', 'max:86400'],
            'priority' => [
                Rule::requiredIf($type?->hasPriority() ?? false),
                'nullable',
                'integer',
                'min:0',
                'max:65535',
            ],
            'proxied' => ['boolean'],
            'comment' => ['nullable', 'string', 'max:255'],
            // Explicit provider targeting; omitted = all compatible enabled providers.
            'providers' => ['nullable', 'array'],
            'providers.*' => ['integer', 'exists:providers,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'The name must be a valid domain name (e.g. app.example.com).',
        ];
    }
}
