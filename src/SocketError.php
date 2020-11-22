<?php

namespace Helix\Socket;

use RuntimeException;

/**
 * A socket error.
 *
 * @see https://php.net/sockets.constants
 */
class SocketError extends RuntimeException {

    /**
     * Extra data, if any. The specific contents are documented where the error is thrown.
     *
     * @var mixed
     */
    protected $extra;

    /**
     * Retrieves and clears the socket error.
     * For resources, this favors `SO_ERROR`, then falls back to the error number set by PHP.
     * Both error codes are cleared.
     *
     * @see https://php.net/socket_last_error
     * @see https://php.net/socket_clear_error
     *
     * @param resource $resource PHP socket resource, or `null` for the global error.
     * @return int If the resource is closed or not a socket, `SOCKET_EBADF` is returned.
     */
    public static function getLast ($resource = null) {
        if (isset($resource)) {
            if (@get_resource_type($resource) !== 'Socket') {
                return SOCKET_EBADF; // Bad file descriptor
            }
            // fetching SO_ERROR also clears it
            elseif (!$errno = socket_get_option($resource, SOL_SOCKET, SO_ERROR)) {
                $errno = socket_last_error($resource);
            }
            socket_clear_error($resource);
            return $errno;
        }
        $errno = socket_last_error();
        socket_clear_error();
        return $errno;
    }

    /**
     * Initializes the error based on a mixed subject.
     *
     * PHP's core socket functions like to return `false` without setting `errno` in some places.
     *
     * To account for that scenario, a fallback code can be given.
     *
     * When the fallback code is used, PHP's last suppressed warning message is used.
     *
     * All scenarios within this library have been meticulously cross-checked with the PHP C source code
     * for cases where a fallback code is necessary.
     *
     * @param int|resource|null $subject Error constant, resource to check, or `NULL` to use the global socket error.
     * @param int $fallback Code to assume if one can't be found via the subject.
     * @param SocketError|null $previous Slippage of a prior error.
     */
    public function __construct ($subject = null, $fallback = 0, SocketError $previous = null) {
        if ($errno = is_int($subject) ? $subject : static::getLast($subject)) {
            $message = socket_strerror($errno);
        }
        else {
            $errno = $fallback;
            $last = error_get_last();
            $message = "{$last['message']} in {$last['file']}:{$last['line']}";
        }
        parent::__construct($message, $errno, $previous);
    }

    /**
     * @return mixed
     */
    public function getExtra () {
        return $this->extra;
    }

    /**
     * @param mixed $extra
     * @return $this
     */
    public function setExtra ($extra) {
        $this->extra = $extra;
        return $this;
    }

}
