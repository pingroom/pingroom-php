<?php

declare(strict_types=1);

namespace PingRoom\Exception;

/**
 * The HTTP client failed before a response arrived (DNS, connection, TLS, ...).
 *
 * The failure the client raised is deliberately not chained as `getPrevious()`:
 * HTTP client exceptions carry the request, and the request carries the
 * Authorization header. Only a message with the token removed is kept.
 */
final class NetworkException extends PingRoomException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'network_error');
    }
}
