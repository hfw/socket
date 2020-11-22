<?php

namespace Helix\Socket;

/**
 * Receives datagrams.
 */
class DatagramServer extends AbstractServer {

    /**
     * `SOCK_DGRAM`
     *
     * @return int
     */
    final public static function getType (): int {
        return SOCK_DGRAM;
    }

    /**
     * Receives data from a peer.
     *
     * @see https://php.net/socket_recvfrom
     *
     * @param int $length Maximum length to read.
     * @param int $flags `MSG_*`
     * @param string $name Assigned the peer's address, or Unix socket file path.
     * @param int $port Assigned the peer's port, or `0` for Unix sockets.
     * @return string
     * @throws SocketError
     */
    public function recv (int $length, int $flags = 0, string &$name = null, int &$port = 0): string {
        $count = @socket_recvfrom($this->resource, $data, $length, $flags, $name, $port);
        if ($count === false) {
            throw new SocketError($this->resource, SOCKET_EOPNOTSUPP);
        }
        return (string)$data; // cast needed, will be null if 0 bytes are read
    }

}