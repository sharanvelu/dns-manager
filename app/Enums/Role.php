<?php

declare(strict_types = 1);

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super-admin';
    case SuperViewer = 'super-viewer';
    case UserAdmin = 'user-admin';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::SuperViewer => 'Super Viewer',
            self::UserAdmin => 'User Admin',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Full access to everything, including all zones and user management.',
            self::SuperViewer => 'Read-only access to everything — zones, records, providers, users, and activity.',
            self::UserAdmin => 'Manage users, their global roles, and zone access — nothing else.',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
