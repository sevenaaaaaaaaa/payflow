<?php

declare(strict_types=1);

namespace PayFlow\Support;

use RuntimeException;

/**
 * Raised when a development run tries to reach a real external service.
 * Extends RuntimeException so existing channel/webhook error handling treats it as a failure,
 * never as a success.
 */
final class OutboundBlockedException extends RuntimeException
{
}
