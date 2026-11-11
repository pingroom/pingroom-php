<?php

declare(strict_types=1);

namespace PingRoom;

/**
 * The default of a parameter where `null` means something to the server and
 * "not given" means something else.
 *
 * `Notifications::replace()` keeps the current title and data when you give
 * neither, and clears them when you pass `null`. `Metrics::set()` keeps the
 * stored unit when you give none and clears it when you pass `null`. You never
 * need to write this case yourself: leave the argument out.
 */
enum Unchanged
{
    case Value;
}
