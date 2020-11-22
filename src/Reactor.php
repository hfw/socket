<?php

namespace Helix\Socket;

use Countable;

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
     * Selects instances.
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
     * Adds a socket for selection.
     *
     * @param ReactiveInterface $socket
     * @return $this
     */
    public function add (ReactiveInterface $socket) {
        $this->sockets[$socket->getId()] = $socket;
        return $this;
    }

    /**
     * The number of sockets in the reactor.
     *
     * @return int
     */
    public function count () {
        return count($this->sockets);
    }

    /**
     * @return SocketInterface[]
     */
    public function getSockets () {
        return $this->sockets;
    }

    /**
     * Selects sockets for readability and calls their reactive methods.
     *
     * Invoke this in a loop that checks the reactor count as a condition.
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
        try {
            foreach ($rwe[2] as $id => $socket) {
                $socket->onOutOfBand();
            }
            foreach ($rwe[0] as $id => $socket) {
                $socket->onReadable();
            }
        }
        finally {
            array_walk_recursive($rwe, function(ReactiveInterface $each) {
                if (!$each->isOpen()) {
                    $this->remove($each);
                }
            });
        }
        return $count;
    }

    /**
     * Removes a socket from the reactor by ID.
     *
     * @param int|ReactiveInterface $id
     * @return $this
     */
    public function remove ($id) {
        unset($this->sockets[$id instanceof ReactiveInterface ? $id->getId() : $id]);
        return $this;
    }

}
