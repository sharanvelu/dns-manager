<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super-admin';
    case DnsManager = 'dns-manager';
    case ProvidersManager = 'providers-manager';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::DnsManager => 'DNS Manager',
            self::ProvidersManager => 'Providers Manager',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Full access, including user and role management.',
            self::DnsManager => 'Create, edit, sync, import, and delete DNS entries.',
            self::ProvidersManager => 'Configure, test, and manage providers.',
            self::Viewer => 'Read-only access to the dashboard, entries, and providers.',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
