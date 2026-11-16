<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;

/**
 * Broadcast manager whose network broadcasters never throw on send. See
 * ResilientBroadcaster. The log and null drivers cannot fail, so they are left
 * as they are.
 */
final class ResilientBroadcastManager extends BroadcastManager
{
    private const LOCAL_DRIVERS = ['log', 'null'];

    /**
     * @param  string  $name
     * @return Broadcaster
     */
    protected function resolve($name)
    {
        $broadcaster = parent::resolve($name);
        $driver = $this->getConfig($name)['driver'] ?? null;

        if (in_array($driver, self::LOCAL_DRIVERS, true) || $broadcaster instanceof ResilientBroadcaster) {
            return $broadcaster;
        }

        return new ResilientBroadcaster($broadcaster, $name);
    }
}
