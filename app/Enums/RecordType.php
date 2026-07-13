<?php

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

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
