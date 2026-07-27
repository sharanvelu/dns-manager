<?php

declare(strict_types = 1);

namespace App\Enums;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Error = 'error';
    case Unchecked = 'unchecked';
}
