<?php

declare(strict_types = 1);

namespace App\Support;

use App\Models\DnsZone;
use App\Enums\RecordType;
use Illuminate\Validation\Rule;

class DnsEntryRules
{
    /**
     * Names are stored relative to their zone: '@' for the apex, 'www',
     * '*.app', multi-label 'a.b', and '_service' labels are all valid.
     */
    private const NAME_REGEX = '/^(@|\*|(\*\.)?_?[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\._?[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*)$/i';

    /**
     * Validation rules for a DNS entry payload, shared by the entry form
     * request, the CSV importer, and the provider import. Passing the zone
     * additionally caps the resulting FQDN length.
     */
    public static function rules(RecordType|string|null $type, ?DnsZone $zone = null): array
    {
        if (is_string($type)) {
            $type = RecordType::tryFrom($type);
        }

        $name = [
            'required',
            'string',
            'max:253',
            'regex:' . self::NAME_REGEX,
        ];

        if ($zone !== null) {
            $name[] = function (string $attribute, mixed $value, \Closure $fail) use ($zone) {
                if (is_string($value) && strlen($zone->fqdn($value)) > 253) {
                    $fail('The resulting hostname exceeds the 253 character DNS limit.');
                }
            };
        }

        return [
            'name' => $name,
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
            'name.regex' => 'The name must be relative to the zone — e.g. www, *.app, or @ for the zone apex.',
        ];
    }
}
