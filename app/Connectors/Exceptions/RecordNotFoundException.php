<?php

namespace App\Connectors\Exceptions;

/**
 * The remote record targeted by an update no longer exists at the provider
 * (deleted out-of-band). Callers may fall back to creating the record.
 */
class RecordNotFoundException extends ConnectorException {}
