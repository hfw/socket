<?php

namespace Helix\Socket;

/**
 * Full duplex connection.
 */
class StreamClient extends AbstractClient {

    /**
     * `SOCK_STREAM`
     *
     * @return int
     */
    final public static function getType (): int {
        return SOCK_STREAM;
    }

    /**
     * Creates a pair of interconnected Unix instances that can be used for IPC.
     *
     * @see https://php.net/socket_create_pair
     *
     * @param array $extra Variadic constructor arguments.
     * @return static[] Two instances at indices `0` and `1`.
     * @throws SocketError
     */
    public static function newUnixPair (...$extra) {
        if (!@socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $fd)) {
            throw new SocketError; // reliable errno
        }
        return [
            new static(...array_merge([$fd[0]], $extra)),
            new static(...array_merge([$fd[1]], $extra))
        ];
    }

    /**
     * Reads the specified length from the peer (forced blocking).
     *
     * May return less than the desired length if the peer shut down writing or closed.
     *
     * @param int $length
     * @return string
     * @throws SocketError The partially read data is attached.
     */
    public function read (int $length): string {
        try {
            $data = '';
            do {
                $data .= $chunk = $this->awaitReadable()->recv($length);
                $length -= $chunkSize = strlen($chunk);
            } while ($chunkSize and $length);
            return $data;
        }
        catch (SocketError $e) {
            $e->setExtra($data);
            throw $e;
        }
    }

    /**
     * Receives up to a specified length from the peer.
     *
     * Returns an empty string if the peer shut down writing or closed.
     *
     * @see https://php.net/socket_recv
     *
     * @param int $maxLength
     * @param int $flags `MSG_*`
     * @return string
     * @throws SocketError
     */
    public function recv (int $maxLength, int $flags = 0): string {
        if (false === @socket_recv($this->resource, $data, $maxLength, $flags)) {
            throw new SocketError($this->resource, SOCKET_EINVAL);
        }
        return (string)$data; // cast needed, will be null if 0 bytes are read.
    }

    /**
     * All available data in the system buffer without blocking.
     *
     * @param int $flags `MSG_*`
     * @return string
     * @throws SocketError
     */
    public function recvAll (int $flags = 0): string {
        $flags = ($flags & ~MSG_WAITALL) | MSG_DONTWAIT;
        $length = $this->getOption(SO_RCVBUF);
        try {
            return $this->recv($length, $flags);
        }
        catch (SocketError $e) {
            if ($e->getCode() === SOCKET_EAGAIN) { // would block
                return '';
            }
            throw $e;
        }
    }

}
