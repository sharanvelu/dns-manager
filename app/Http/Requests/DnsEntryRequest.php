<?php

declare(strict_types = 1);

namespace App\Http\Requests;

use App\Models\DnsZone;
use App\Models\DnsEntry;
use App\Enums\RecordType;
use App\Support\DnsEntryRules;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class DnsEntryRequest extends FormRequest
{
    /**
     * The zone comes from the payload (store) or the bound entry (update),
     * never the URL — record management is authorized per zone here.
     */
    public function authorize(): bool
    {
        $zone = $this->targetZone();

        // A missing/unknown zone is a validation problem (422 from the
        // required|exists rules), not an authorization one — 403 is reserved
        // for real zones the user cannot manage records in.
        if ($zone === null) {
            return true;
        }

        return $this->user()->can('manageRecords', $zone);
    }

    public function rules(): array
    {
        $type = RecordType::tryFrom((string) $this->input('type'));
        $zone = $this->targetZone();

        return [
            // The zone is immutable after creation — updates ignore it.
            ...$this->isUpdate() ? [] : [
                'dns_zone_id' => ['required', 'integer', 'exists:dns_zones,id'],
            ],
            ...DnsEntryRules::rules($type, $zone),
            // Explicit targeting of the zone's provider attachments;
            // omitted = all compatible active attachments.
            'zone_providers' => ['nullable', 'array'],
            'zone_providers.*' => [
                'integer',
                Rule::exists('zone_providers', 'id')->where('dns_zone_id', $zone?->id ?? 0),
            ],
        ];
    }

    public function messages(): array
    {
        return DnsEntryRules::messages();
    }

    /**
     * Normalize the submitted name to its zone-relative form so a pasted
     * FQDN ("www.example.com" in zone example.com) still validates.
     */
    protected function prepareForValidation(): void
    {
        if (! is_string($this->input('name'))) {
            return;
        }

        $name = strtolower(rtrim(trim($this->input('name')), '.'));

        if ($zone = $this->targetZone()) {
            if ($name === $zone->name) {
                $name = '@';
            } elseif (str_ends_with($name, '.' . $zone->name)) {
                $name = substr($name, 0, -strlen('.' . $zone->name));
            }
        }

        $this->merge(['name' => $name]);
    }

    protected function isUpdate(): bool
    {
        return $this->route('entry') !== null;
    }

    protected function targetZone(): ?DnsZone
    {
        $entry = $this->route('entry');

        if ($entry instanceof DnsEntry) {
            return $entry->zone;
        }

        return DnsZone::find($this->input('dns_zone_id'));
    }
}
