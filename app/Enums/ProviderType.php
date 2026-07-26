<?php

namespace App\Enums;

enum ProviderType: string
{
    case Cloudflare = 'cloudflare';
    case Pihole = 'pihole';
    case Technitium = 'technitium';

    public function label(): string
    {
        return match ($this) {
            self::Cloudflare => 'Cloudflare',
            self::Pihole => 'Pi-hole',
            self::Technitium => 'Technitium',
        };
    }
}
