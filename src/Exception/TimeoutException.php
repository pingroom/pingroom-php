<?php

declare(strict_types=1);

namespace PingRoom\Exception;

/** The request did not finish in time. A send that timed out may still have been delivered: retry with the same idempotency key. */
final class TimeoutException extends PingRoomException
{
    public function __construct(string $message = 'Request timed out')
    {
        parent::__construct($message, 'timeout');
    }
}
