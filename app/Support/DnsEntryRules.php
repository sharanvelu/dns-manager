<?php

namespace App\Support;

use App\Enums\RecordType;
use Illuminate\Validation\Rule;

class DnsEntryRules
{
    /**
     * Validation rules for a DNS entry payload, shared by the entry form
     * request and the CSV importer.
     */
    public static function rules(?RecordType $type): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:253',
                // Labels may start with "_" for service/verification records
                // (_sip._tcp.example.com, _dmarc.example.com).
                'regex:/^(\*\.)?(_?[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*_?[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/',
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
        ];
    }

    public static function messages(): array
    {
        return [
            'name.regex' => 'The name must be a valid domain name (e.g. app.example.com).',
        ];
    }
}
