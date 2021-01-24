<?php

namespace Helix\Socket\WebSocket;

use Countable;
use Exception;
use Helix\Socket\ReactiveInterface;
use Helix\Socket\Reactor;
use Helix\Socket\StreamServer;

/**
 * A WebSocket server.
 *
 * @see https://tools.ietf.org/html/rfc6455
 */
class WebSocketServer extends StreamServer implements Countable, ReactiveInterface {

    /**
     * Holds all connected clients.
     *
     * @var WebSocketClient[]
     */
    protected $clients = [];

    /**
     * @var Reactor
     */
    protected $reactor;

    /**
     * @param $resource
     * @param Reactor $reactor
     */
    public function __construct ($resource, Reactor $reactor) {
        parent::__construct($resource);
        $reactor->add($this);
        $this->reactor = $reactor;
    }

    /**
     * @return WebSocketClient
     */
    public function accept (): WebSocketClient {
        /**
         * @see newClient()
         * @var WebSocketClient $client
         */
        $client = parent::accept();
        $this->clients[$client->getId()] = $client;
        $this->reactor->add($client);
        return $client;
    }

    /**
     * Sends a payload to all clients in the OK state.
     *
     * @param int $opCode
     * @param string $payload
     */
    public function broadcast (int $opCode, string $payload) {
        foreach ($this->clients as $client) {
            if ($client->isOk()) {
                $client->getFrameHandler()->write($opCode, $payload);
            }
        }
    }

    /**
     * @param string $payload
     */
    public function broadcastBinary (string $payload) {
        $this->broadcast(Frame::OP_BINARY, $payload);
    }

    /**
     * Sends a ping to all clients in the OK state.
     *
     * @param string $payload
     */
    public function broadcastPing (string $payload = '') {
        $this->broadcast(Frame::OP_PING, $payload);
    }

    /**
     * Sends a message to all clients in the OK state.
     *
     * @param string $text
     */
    public function broadcastText (string $text) {
        $this->broadcast(Frame::OP_TEXT, $text);
    }

    /**
     * Closes and removes all clients.
     *
     * @param int $code
     * @param string $reason
     * @return $this
     */
    public function close (int $code = Frame::CLOSE_INTERRUPT, $reason = '') {
        foreach ($this->clients as $client) {
            try {
                $client->close($code, $reason);
            }
            catch (Exception $e) {
                continue;
            }
        }
        $this->reactor->remove($this);
        return parent::close();
    }

    /**
     * The number of clients attached.
     *
     * @return int
     */
    public function count (): int {
        return count($this->clients);
    }

    /**
     * @return WebSocketClient[]
     */
    public function getClients () {
        return $this->clients;
    }

    /**
     * @param resource $resource
     * @return WebSocketClient
     */
    protected function newClient ($resource): WebSocketClient {
        return new WebSocketClient($resource, $this);
    }

    /**
     * WebSocket servers never get OOB data.
     */
    final public function onOutOfBand (): void {
        // do nothing
    }

    /**
     * Auto-accept.
     */
    public function onReadable (): void {
        $this->accept();
    }

    /**
     * Removes the client from the server and reactor.
     *
     * @param WebSocketClient $client
     */
    public function remove ($client): void {
        unset($this->clients[$client->getId()]);
        $this->reactor->remove($client);
    }
}