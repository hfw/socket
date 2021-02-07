<?php

namespace Helix\Socket;

use Countable;
use Helix\Socket\WebSocket\WebSocketClient;
use Helix\Socket\WebSocket\WebSocketError;
use Throwable;

/**
 * Selects and calls reactive sockets when they are readable.
 */
class Reactor implements Countable {

    /**
     * All sockets in the reactor, keyed by ID.
     *
     * @var ReactiveInterface[]
     */
    protected $sockets = [];

    /**
     * Selects instances. Can be used to select non-reactive sockets.
     *
     * @see https://php.net/socket_select
     *
     * @param SocketInterface[] $read
     * @param SocketInterface[] $write
     * @param SocketInterface[] $except
     * @param float|null $timeout Maximum seconds to block. `NULL` blocks forever.
     * @return int
     * @throws SocketError
     */
    public static function select (array &$read, array &$write, array &$except, ?float $timeout = null): int {
        $rwe = [$read, $write, $except];
        array_walk_recursive($rwe, function(SocketInterface &$each) {
            $each = $each->getResource();
        });
        $uSec = (int)(fmod($timeout, 1) * 1000000); // ignored if timeout is null
        $count = @socket_select($rwe[0], $rwe[1], $rwe[2], $timeout, $uSec); // keys are preserved
        if ($count === false) {
            $read = $write = $except = [];
            throw new SocketError;
        }
        $read = array_intersect_key($read, $rwe[0]);
        $write = array_intersect_key($write, $rwe[1]);
        $except = array_intersect_key($except, $rwe[2]);
        return $count;
    }

    /**
     * Adds a reactive socket for selection.
     *
     * @param ReactiveInterface $socket
     * @return $this
     */
    public function add (ReactiveInterface $socket) {
        $this->sockets[$socket->getId()] = $socket;
        return $this;
    }

    /**
     * The number of reactive sockets in the reactor.
     *
     * @return int
     */
    public function count (): int {
        return count($this->sockets);
    }

    /**
     * @return ReactiveInterface[]
     */
    public function getSockets () {
        return $this->sockets;
    }

    /**
     * Whether a socket is in the reactor.
     *
     * @param ReactiveInterface $socket
     * @return bool
     */
    public function has (ReactiveInterface $socket): bool {
        return isset($sockets[$socket->getId()]);
    }

    /**
     * @param int $channel
     * @param ReactiveInterface $socket
     * @param Throwable $error
     */
    public function onError (int $channel, $socket, Throwable $error): void {
        unset($channel);
        if ($socket instanceof WebSocketClient and $error instanceof WebSocketError) {
            if ($socket->isOpen()) {
                $socket->close($error->getCode(), $error->getMessage());
            }
        }
        else {
            if ($socket->isOpen()) {
                $socket->close();
            }
        }
    }

    /**
     * Selects the reactor's sockets and calls their reactive methods.
     *
     * Invoke this in a loop that checks {@link Reactor::count()} a condition.
     *
     * Closed sockets are automatically removed from the reactor.
     *
     * @param float|null $timeout Maximum seconds to block. `NULL` blocks forever.
     * @return int Number of sockets selected.
     */
    public function react (?float $timeout = null): int {
        /** @var ReactiveInterface[][] $rwe */
        $rwe = [$this->sockets, [], $this->sockets];
        $count = static::select($rwe[0], $rwe[1], $rwe[2], $timeout);
        foreach ([2 => 'onOutOfBand', 0 => 'onReadable'] as $channel => $method) {
            foreach ($rwe[$channel] as $id => $socket) {
                try {
                    $socket->{$method}();
                }
                catch (Throwable $error) {
                    unset($rwe[0][$id]); // prevent onReadable() if this is an OOB error.
                    $this->onError($channel, $socket, $error);
                }
                finally {
                    if (!$socket->isOpen() and $this->has($socket)) {
                        $this->remove($socket);
                    }
                }
            }
        }
        return $count;
    }

    /**
     * Removes a socket from the reactor.
     *
     * @param ReactiveInterface $socket
     * @return $this
     */
    public function remove (ReactiveInterface $socket) {
        unset($this->sockets[$socket->getId()]);
        return $this;
    }
}
