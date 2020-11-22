<?php

namespace Helix\Socket\WebSocket;

use Exception;
use Helix\Socket\ReactiveInterface;
use Helix\Socket\StreamClient;

/**
 * A WebSocket client.
 *
 * @see https://tools.ietf.org/html/rfc6455
 */
class WebSocketClient extends StreamClient implements ReactiveInterface {

    const STATE_HANDSHAKE = 0;
    const STATE_OK = 1;
    const STATE_CLOSE = 2;

    /**
     * @var FrameHandler
     */
    protected $frameHandler;

    /**
     * @var FrameReader
     */
    protected $frameReader;

    /**
     * @var HandShake
     */
    protected $handshake;

    /**
     * @var MessageHandler
     */
    protected $messageHandler;

    /**
     * @var WebSocketServer
     */
    protected $server;

    /**
     * @var int
     */
    protected $state = self::STATE_HANDSHAKE;

    /**
     * @param $resource
     * @param WebSocketServer $server
     */
    public function __construct ($resource, WebSocketServer $server) {
        parent::__construct($resource);
        $this->server = $server;
    }

    /**
     * Closes, optionally with a code and reason sent to the peer.
     *
     * https://tools.ietf.org/html/rfc6455#section-5.5.1
     * > The application MUST NOT send any more data frames after sending a
     * > Close frame.
     * >
     * > After both sending and receiving a Close message, an endpoint
     * > considers the WebSocket connection closed and MUST close the
     * > underlying TCP connection.
     *
     * https://tools.ietf.org/html/rfc6455#section-7.4.2
     * > Status codes in the range 0-999 are not used.
     *
     * @param int|null $code Only used if `>= 1000`
     * @param string $reason
     * @return StreamClient|void
     */
    public function close (int $code = null, string $reason = '') {
        try {
            if ($code >= 1000 and $this->state === self::STATE_OK) {
                $this->getFrameHandler()->writeClose($code, $reason);
                $this->shutdown(self::CH_WRITE);
            }
        }
        finally {
            $this->state = self::STATE_CLOSE;
            $this->server->remove($this);
            parent::close();
        }
    }

    /**
     * @return FrameHandler
     */
    public function getFrameHandler () {
        return $this->frameHandler ?? $this->frameHandler = new FrameHandler($this);
    }

    /**
     * @return FrameReader
     */
    public function getFrameReader () {
        return $this->frameReader ?? $this->frameReader = new FrameReader($this);
    }

    /**
     * @return HandShake
     */
    public function getHandshake () {
        return $this->handshake ?? $this->handshake = new HandShake($this);
    }

    /**
     * @return MessageHandler
     */
    public function getMessageHandler () {
        return $this->messageHandler ?? $this->messageHandler = new MessageHandler($this);
    }

    /**
     * @return WebSocketServer
     */
    public function getServer () {
        return $this->server;
    }

    /**
     * @return int
     */
    public function getState (): int {
        return $this->state;
    }

    /**
     * @return bool
     */
    final public function isOk (): bool {
        return $this->state === self::STATE_OK;
    }

    /**
     * WebSockets do not use the out-of-band channel.
     *
     * The RFC says the connection must be dropped if any unsupported activity occurs.
     */
    final public function onOutOfBand (): void {
        $this->close(Frame::CLOSE_PROTOCOL_ERROR, "Received out-of-band data.");
    }

    /**
     * Delegates received data to handlers.
     *
     * @throws Exception
     */
    public function onReadable (): void {
        if (!strlen($this->recv(1, MSG_PEEK))) { // peer has shut down writing, or closed.
            $this->close();
            return;
        }
        try {
            switch ($this->state) {
                case self::STATE_HANDSHAKE:
                    if ($this->getHandshake()->negotiate()) {
                        $this->state = self::STATE_OK;
                        $this->onStateOk();
                    }
                    return;
                case self::STATE_OK:
                    $frameHandler = $this->getFrameHandler();
                    foreach ($this->getFrameReader()->getFrames() as $frame) {
                        $frameHandler->onFrame($frame);
                    }
                    return;
                case self::STATE_CLOSE:
                    return;
            }
        }
        catch (WebSocketError $e) {
            $this->close($e->getCode(), $e->getMessage());
            throw $e;
        }
        catch (Exception $e) {
            $this->close(Frame::CLOSE_INTERNAL_ERROR);
            throw $e;
        }
    }

    /**
     * Stub.
     */
    protected function onStateOk (): void {

    }

    /**
     * @param FrameHandler $frameHandler
     * @return $this
     */
    public function setFrameHandler (FrameHandler $frameHandler) {
        $this->frameHandler = $frameHandler;
        return $this;
    }

    /**
     * @param FrameReader $frameReader
     * @return $this
     */
    public function setFrameReader (FrameReader $frameReader) {
        $this->frameReader = $frameReader;
        return $this;
    }

    /**
     * @param MessageHandler $messageHandler
     * @return $this
     */
    public function setMessageHandler (MessageHandler $messageHandler) {
        $this->messageHandler = $messageHandler;
        return $this;
    }

}