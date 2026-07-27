<?php

declare(strict_types = 1);

namespace App\Enums;

enum ZoneRole: string
{
    case ZoneAdmin = 'zone-admin';
    case ZoneDnsManager = 'zone-dns-manager';
    case ZoneViewer = 'zone-viewer';
    case ZoneProviderManager = 'zone-provider-manager';

    public function label(): string
    {
        return match ($this) {
            self::ZoneAdmin => 'Zone Admin',
            self::ZoneDnsManager => 'DNS Manager',
            self::ZoneViewer => 'Viewer',
            self::ZoneProviderManager => 'Provider Manager',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ZoneAdmin => 'Everything in this zone except deleting it — records, imports, attachments, activity, and access grants.',
            self::ZoneDnsManager => 'Create, edit, sync, import, and delete DNS records in this zone.',
            self::ZoneViewer => 'Read-only access to this zone, its records, and its activity.',
            self::ZoneProviderManager => 'Manage this zone\'s provider attachments only.',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
