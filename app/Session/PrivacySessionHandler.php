<?php

namespace App\Session;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Database sessions without IP address or user agent for guests
 * (privacy requirement: no IP storage for guests). Signed-in users keep
 * them so they can review their active sessions.
 */
class PrivacySessionHandler extends DatabaseSessionHandler
{
    /**
     * @param  array<string, mixed>  $payload
     * @return $this
     */
    protected function addRequestInformation(&$payload)
    {
        if ($this->container?->bound('request') && $this->userId() !== null) {
            parent::addRequestInformation($payload);
        }

        return $this;
    }
}
