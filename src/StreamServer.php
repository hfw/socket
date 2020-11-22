<?php

namespace Helix\Socket;

/**
 * Server that accepts and wraps incoming connections as client instances.
 */
class StreamServer extends AbstractServer {

    /**
     * `SOCK_STREAM`
     *
     * @return int
     */
    final public static function getType (): int {
        return SOCK_STREAM;
    }

    /**
     * Accepts an incoming client connection.
     * This will block unless the server was selected for reading.
     *
     * @see https://php.net/socket_accept
     *
     * @return StreamClient
     * @throws SocketError
     */
    public function accept () {
        if (!$resource = @socket_accept($this->resource)) {
            throw new SocketError($this->resource); // reliable errno
        }
        return $this->newClient($resource);
    }

    /**
     * Enables incoming connections.
     *
     * Listening without binding first will cause the socket to bind to a random port on *all* network interfaces.
     *
     * @see https://php.net/socket_listen
     *
     * @see bind()
     *
     * @param int $backlog Connection queue size, or `0` to use the system's default.
     * @return $this
     * @throws SocketError
     */
    public function listen (int $backlog = 0) {
        if (!@socket_listen($this->resource, $backlog)) {
            throw new SocketError($this->resource); // reliable errno
        }
        return $this;
    }

    /**
     * Wraps an accepted connection.
     *
     * @param resource $resource The accepted connection.
     * @return StreamClient
     */
    protected function newClient ($resource) {
        return new StreamClient($resource);
    }

}
