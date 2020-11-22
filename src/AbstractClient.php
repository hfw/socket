<?php

namespace Helix\Socket;

use Throwable;

/**
 * Abstract client socket.
 */
abstract class AbstractClient extends AbstractSocket {

    /**
     * The peer's name as `<address>:<port>`,
     * or `<pid>:0` for Unix sockets,
     * or `?<id>` if a name can't be derived (e.g. the socket is closed).
     *
     * @see getPeerName()
     *
     * @return string
     */
    public function __toString () {
        try {
            return implode(':', $this->getPeerName());
        }
        catch (Throwable $e) {
            return "?{$this->resource}";
        }
    }

    /**
     * Connects the socket to a peer.
     * For datagram sockets this sets the target recipient.
     *
     * For non-blocking sockets, the `SOCKET_EINPROGRESS` and `SOCKET_EWOULDBLOCK` errors
     * are ignored and cleared, because they're expected.
     *
     * @see https://php.net/socket_connect
     *
     * @param string $address The remote network address, or local Unix file name.
     * @param int $port The remote port. Unix sockets ignore this entirely.
     * @return $this
     * @throws SocketError
     */
    public function connect (string $address, int $port = 0) {
        if (!@socket_connect($this->resource, $address, $port)) {
            // ignore expected errors for non-blocking connections
            $errno = SocketError::getLast($this->resource);
            if ($errno !== SOCKET_EINPROGRESS and $errno !== SOCKET_EWOULDBLOCK) {
                // $address or $port could be bad, or fallback to EINVAL
                throw new SocketError($errno, SOCKET_EINVAL);
            }
        }
        return $this;
    }

    /**
     * The peer's address and port, or Unix PID and port `0`.
     *
     * @see https://php.net/socket_getpeername
     *
     * @return array `[ 0 => address, 1 => port ]`
     * @throws SocketError
     */
    public function getPeerName (): array {
        if ($this->getDomain() === AF_UNIX) {
            return [$this->getOption(17), 0]; // SO_PEERCRED is not exposed by PHP
        }
        if (!@socket_getpeername($this->resource, $addr, $port)) {
            throw new SocketError($this->resource, SOCKET_EAFNOSUPPORT);
        }
        return [$addr, $port];
    }

    /**
     * Sends data to the remote peer.
     *
     * @see https://php.net/socket_send
     *
     * @param string $data
     * @param int $flags `MSG_*`
     * @return int Total bytes sent.
     * @throws SocketError
     */
    public function send (string $data, int $flags = 0): int {
        $count = @socket_send($this->resource, $data, strlen($data), $flags);
        if ($count === false) {
            throw new SocketError($this->resource); // reliable errno
        }
        return $count;
    }

    /**
     * Sends all data (forced blocking).
     *
     * @param string $data
     * @return $this
     * @throws SocketError `int` total bytes sent is set as the extra data.
     */
    public function write (string $data) {
        $length = strlen($data);
        $total = 0;
        while ($total < $length) {
            try {
                $total += $this->awaitWritable()->send(substr($data, $total));
            }
            catch (SocketError $e) {
                $e->setExtra($total);
                throw $e;
            }
        }
        return $this;
    }

}
