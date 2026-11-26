<?php

declare(strict_types = 1);

namespace App\Enums;

enum RecordType: string
{
    case A = 'A';
    case AAAA = 'AAAA';
    case CNAME = 'CNAME';
    case MX = 'MX';
    case TXT = 'TXT';
    case SRV = 'SRV';
    case NS = 'NS';
    case CAA = 'CAA';
    case PTR = 'PTR';

    /**
     * Record types that carry a priority value.
     */
    public function hasPriority(): bool
    {
        return in_array($this, [self::MX, self::SRV], true);
    }

    /**
     * Record types a provider proxy can sit in front of (Cloudflare proxies
     * only A, AAAA, and CNAME).
     */
    public function isProxiable(): bool
    {
        return in_array($this, [self::A, self::AAAA, self::CNAME], true);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
