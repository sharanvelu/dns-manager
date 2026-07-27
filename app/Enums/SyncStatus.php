<?php

declare(strict_types = 1);

namespace App\Enums;

enum SyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Drifted = 'drifted';
    case Error = 'error';
    case Deleting = 'deleting';
}
