<?php

namespace Helix\Socket;

use InvalidArgumentException;

/**
 * Abstract parent to all sockets.
 */
abstract class AbstractSocket implements SocketInterface {

    /**
     * The `SOCK_*` type constant for the class.
     *
     * @return int
     */
    abstract public static function getType (): int;

    /**
     * The underlying PHP resource.
     *
     * @var resource
     */
    protected $resource;

    /**
     * Creates an instance of the called class.
     *
     * @see https://php.net/socket_create
     *
     * @param int $domain `AF_*` constant.
     * @param array $extra Variadic constructor arguments.
     * @return static
     * @throws SocketError
     */
    public static function create (int $domain = AF_INET, ...$extra) {
        if (!$resource = @socket_create($domain, static::getType(), 0)) { // auto-protocol
            throw new SocketError; // reliable errno
        }
        return new static($resource, ...$extra);
    }

    /**
     * Validates and sets the underlying socket resource.
     *
     * The resource must be open, of the correct type, and have no pending errors.
     *
     * @param resource $resource PHP socket resource.
     * @throws InvalidArgumentException Not a socket resource, or the socket is of the wrong type.
     * @throws SocketError Slippage of an existing error on the resource.
     */
    public function __construct ($resource) {
        if (!is_resource($resource) or get_resource_type($resource) !== 'Socket') {
            throw new InvalidArgumentException('Expected an open socket resource.', SOCKET_EBADF);
        }
        elseif (socket_get_option($resource, SOL_SOCKET, SO_TYPE) !== static::getType()) {
            throw new InvalidArgumentException('Invalid socket type for ' . static::class, SOCKET_ESOCKTNOSUPPORT);
        }
        elseif ($errno = SocketError::getLast($resource)) {
            // "File descriptor in bad state"
            throw new SocketError(SOCKET_EBADFD, 0, new SocketError($errno));
        }
        $this->resource = $resource;
    }

    /**
     * Closes the socket if it's open.
     *
     * @see close()
     */
    public function __destruct () {
        if ($this->isOpen()) {
            $this->close();
        }
    }

    /**
     * Blocks until the socket becomes available on a given channel.
     *
     * Throws if the underlying resource has a pending error (non-blocking sockets only).
     *
     * @see isReady()
     *
     * @param int $channel {@link SocketInterface} channel constant.
     * @return $this
     * @throws SocketError
     */
    public function await (int $channel) {
        $rwe = [$channel => [$this->resource]];
        if (!@socket_select($rwe[0], $rwe[1], $rwe[2], null)) {
            throw new SocketError($this->resource);
        }
        return $this;
    }

    /**
     * @see await()
     *
     * @return $this
     */
    final public function awaitOutOfBand () {
        return $this->await(self::CH_EXCEPT);
    }

    /**
     * @see await()
     *
     * @return $this
     */
    final public function awaitReadable () {
        return $this->await(self::CH_READ);
    }

    /**
     * @see await()
     *
     * @return $this
     */
    final public function awaitWritable () {
        return $this->await(self::CH_WRITE);
    }

    /**
     * Closes the underlying resource.
     *
     * This should not be called more than once.
     *
     * @see https://php.net/socket_close
     *
     * @return $this
     */
    public function close () {
        socket_close($this->resource);
        return $this;
    }

    /**
     * The `AF_*` address family constant.
     *
     * @return int
     */
    final public function getDomain (): int {
        return $this->getOption(39); // SO_DOMAIN is not exposed by PHP
    }

    /**
     * @return int
     */
    final public function getId (): int {
        return (int)$this->resource;
    }

    /**
     * Retrieves an option value.
     *
     * @see https://php.net/socket_get_option
     *
     * @param int $option `SO_*` option constant.
     * @return mixed The option's value. This is never `false`.
     * @throws SocketError
     */
    public function getOption (int $option) {
        $value = @socket_get_option($this->resource, SOL_SOCKET, $option);
        if ($value === false) {
            throw new SocketError($this->resource, SOCKET_EINVAL);
        }
        return $value;
    }

    /**
     * @return resource
     */
    final public function getResource () {
        return $this->resource;
    }

    /**
     * The local address and port, or Unix file path and port `0`.
     *
     * @see https://php.net/socket_getsockname
     *
     * @return array `[ 0 => address, 1 => port ]`
     * @throws SocketError
     */
    public function getSockName (): array {
        if (!@socket_getsockname($this->resource, $addr, $port)) {
            throw new SocketError($this->resource, SOCKET_EOPNOTSUPP);
        }
        return [$addr, $port];
    }

    /**
     * @return bool
     */
    public function isOpen (): bool {
        return is_resource($this->resource);
    }

    /**
     * Polls for whether the socket can perform a non-blocking out-of-band read.
     *
     * @see isReady()
     *
     * @return bool
     */
    final public function isOutOfBand (): bool {
        return $this->isReady(self::CH_EXCEPT);
    }

    /**
     * Polls for whether the socket can perform a non-blocking read.
     *
     * @see isReady()
     *
     * @return bool
     */
    final public function isReadable (): bool {
        return $this->isReady(self::CH_READ);
    }

    /**
     * Selects for channel availability.
     *
     * @see await()
     *
     * @param int $channel `SocketInterface` channel constant.
     * @param float|null $timeout Maximum seconds to block. `NULL` blocks forever. Defaults to a poll.
     * @return bool
     * @throws SocketError
     */
    public function isReady (int $channel, ?float $timeout = 0): bool {
        $rwe = [$channel => [$this->resource]];
        // core casts non-null timeout to int.
        // usec is ignored if timeout is null.
        $usec = (int)(fmod($timeout, 1) * 1000000);
        if (false === $count = @socket_select($rwe[0], $rwe[1], $rwe[2], $timeout, $usec)) {
            throw new SocketError($this->resource);
        }
        return (bool)$count;
    }

    /**
     * Polls for whether the socket can perform a non-blocking write.
     *
     * @see isReady()
     *
     * @return bool
     */
    final public function isWritable (): bool {
        return $this->isReady(self::CH_WRITE);
    }

    /**
     * Enables or disables blocking.
     *
     * **Note: PHP provides no way to retrieve a socket's blocking mode.**
     *
     * Sockets are always created in blocking mode.
     *
     * If you're using a reactor, keep the sockets in blocking mode.
     *
     * Non-blocking errors are thrown when performing ordinary operations, even if unrelated to those operations.
     * This is known as error slippage.
     * To test for and clear an existing non-blocking error, use `->getOption(SO_ERROR)`.
     *
     * @see https://php.net/socket_set_block
     * @see https://php.net/socket_set_nonblock
     *
     * @param bool $blocking Whether the socket should block.
     * @return $this
     * @throws SocketError
     */
    public function setBlocking (bool $blocking) {
        if ($blocking ? @socket_set_block($this->resource) : @socket_set_nonblock($this->resource)) {
            return $this;
        }
        throw new SocketError($this->resource); // reliable errno
    }

    /**
     * Sets an option on the underlying resource.
     *
     * @see https://php.net/socket_set_option
     *
     * @param int $option `SO_*` constant.
     * @param mixed $value
     * @return $this
     * @throws SocketError
     */
    public function setOption (int $option, $value) {
        if (!@socket_set_option($this->resource, SOL_SOCKET, $option, $value)) {
            throw new SocketError($this->resource, SOCKET_EINVAL);
        }
        return $this;
    }

    /**
     * Sets the I/O timeout length in seconds.
     *
     * @param float $timeout Zero means "no timeout" (block forever).
     * @return $this
     */
    public function setTimeout (float $timeout) {
        $tv = [
            'sec' => (int)$timeout,
            'usec' => (int)(fmod($timeout, 1) * 1000000)
        ];
        $this->setOption(SO_RCVTIMEO, $tv);
        $this->setOption(SO_SNDTIMEO, $tv);
        return $this;
    }

    /**
     * Shuts down I/O for a single channel.
     *
     * Shutting down is a formal handshake two peers can do before actually closing.
     * It's not required, but it can help assert I/O access logic.
     *
     * If writing is shut down, trying to send will throw `SOCKET_EPIPE`,
     * and the remote peer will read an empty string after receiving all pending data.
     *
     * If reading is shut down, trying to receive will return an empty string,
     * and the remote peer will get an `EPIPE` error if they try to send.
     *
     * Writing should be shut down first between two connected sockets.
     *
     * Selection always returns positive for a shut down channel,
     * and the remote peer will similarly have positive selection for the opposite channel.
     *
     * @see https://php.net/socket_shutdown
     *
     * @param int $channel `CH_READ` or `CH_WRITE`
     * @return $this
     * @throws SocketError
     */
    public function shutdown (int $channel) {
        if (!@socket_shutdown($this->resource, $channel)) {
            throw new SocketError($this->resource); // reliable errno
        }
        return $this;
    }

}