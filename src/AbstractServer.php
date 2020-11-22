<?php

namespace Helix\Socket;

use Throwable;

/**
 * Abstract server socket.
 */
abstract class AbstractServer extends AbstractSocket {

    /**
     * The server's name as `<address>:<port>`,
     * or `<filepath>:0` for Unix sockets,
     * or `?<id>` if a name can't be derived (e.g. the socket is closed).
     *
     * @see getSockName()
     *
     * @return string
     */
    public function __toString () {
        try {
            return implode(':', $this->getSockName());
        }
        catch (Throwable $e) {
            return "?{$this->resource}";
        }
    }

    /**
     * Binds to an address and port, or file path (Unix) for the OS to create, so the server can listen.
     *
     * Unix sockets do not honor `SO_REUSEADDR`.
     * It is your responsibility to remove unused socket files.
     *
     * @see https://php.net/socket_bind
     *
     * @param string $address
     * @param int $port Zero for a random unused network port. Unix sockets ignore the port entirely.
     * @return $this
     * @throws SocketError
     */
    public function bind (string $address, int $port = 0) {
        if (!@socket_bind($this->resource, $address, $port)) {
            throw new SocketError($this->resource, SOCKET_EOPNOTSUPP);
        }
        return $this;
    }

}
