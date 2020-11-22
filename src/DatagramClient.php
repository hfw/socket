<?php

namespace Helix\Socket;

/**
 * Broadcasts datagrams.
 */
class DatagramClient extends AbstractClient {

    /**
     * `SOCK_DGRAM`
     *
     * @return int
     */
    final public static function getType (): int {
        return SOCK_DGRAM;
    }

}
