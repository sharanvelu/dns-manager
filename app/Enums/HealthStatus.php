<?php

namespace App\Enums;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Error = 'error';
    case Unchecked = 'unchecked';
}
