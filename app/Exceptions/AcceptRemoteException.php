<?php

declare(strict_types = 1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Accepting a provider's values for a drifted entry was refused — the
 * message is user-facing and nothing has been changed.
 */
class AcceptRemoteException extends RuntimeException
{
}
