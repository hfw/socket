<?php

namespace Helix\Socket\WebSocket;

use Helix\Socket\ReactiveInterface;
use Helix\Socket\StreamClient;
use Throwable;

/**
 * Wraps a WebSocket peer.
 *
 * @see https://tools.ietf.org/html/rfc6455
 */
class WebSocketClient extends StreamClient implements ReactiveInterface {

    /**
     * The peer has connected but hasn't negotiated a session yet.
     */
    const STATE_HANDSHAKE = 0;

    /**
     * The session is active and the client can perform frame I/O with the peer.
     */
    const STATE_OK = 1;

    /**
     * The peer has disconnected.
     */
    const STATE_CLOSED = 2;

    /**
     * @var FrameHandler
     */
    protected $frameHandler;

    /**
     * @var Handshake
     */
    protected $handshake;

    /**
     * @var WebSocketServer
     */
    protected $server;

    /**
     * @var int
     */
    protected $state = self::STATE_HANDSHAKE;

    /**
     * @param resource $resource
     * @param WebSocketServer $server
     */
    public function __construct ($resource, WebSocketServer $server) {
        parent::__construct($resource);
        $this->server = $server;
        $this->handshake = new Handshake($this);
        $this->frameHandler = new FrameHandler($this);
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
     * @param int|null $code Sent to the peer if >= 1000
     * @param string $reason Sent to the peer, if code is >= 1000
     * @return $this
     */
    public function close (int $code = null, string $reason = '') {
        try {
            if ($code >= 1000 and $this->isOk()) {
                $this->frameHandler->writeClose($code, $reason);
            }
        }
        finally {
            $this->server->remove($this);
            parent::close();
            $this->state = self::STATE_CLOSED;
        }
        return $this;
    }

    /**
     * @return FrameHandler
     */
    public function getFrameHandler (): FrameHandler {
        return $this->frameHandler;
    }

    /**
     * @return WebSocketServer
     */
    public function getServer (): WebSocketServer {
        return $this->server;
    }

    /**
     * @return int
     */
    public function getState (): int {
        return $this->state;
    }

    final public function isNegotiating (): bool {
        return $this->state === self::STATE_HANDSHAKE;
    }

    /**
     * @return bool
     */
    final public function isOk (): bool {
        return $this->state === self::STATE_OK;
    }

    /**
     * Called when a complete `BINARY` payload is received from the peer.
     *
     * Throws by default.
     *
     * @param string $binary
     * @throws WebSocketError
     */
    public function onBinary (string $binary): void {
        unset($binary);
        throw new WebSocketError(Frame::CLOSE_UNHANDLED_DATA, "I don't handle binary data.");
    }

    /**
     * Called when a `CLOSE` frame is received from the peer.
     *
     * @param int $code
     * @param string $reason
     */
    public function onClose (int $code, string $reason): void {
        unset($code, $reason);
        $this->close();
    }

    /**
     * WebSockets do not use the out-of-band channel.
     *
     * The RFC says the connection must be dropped if any unsupported activity occurs.
     *
     * Closes the connection with a protocol-error frame.
     */
    final public function onOutOfBand (): void {
        $this->close(Frame::CLOSE_PROTOCOL_ERROR, "Received out-of-band data.");
    }

    /**
     * Called when a `PING` is received from the peer.
     *
     * Automatically PONGs back the payload back by default.
     *
     * @param string $message
     */
    public function onPing (string $message): void {
        $this->frameHandler->writePong($message);
    }

    /**
     * Called when a `PONG` is received from the peer.
     *
     * Does nothing by default.
     *
     * @param string $message
     */
    public function onPong (string $message): void {
        // stub
    }

    /**
     * Delegates the read-channel to handlers.
     *
     * @throws WebSocketError
     * @throws Throwable
     */
    public function onReadable (): void {
        try {
            if ($this->isNegotiating()) {
                if ($this->handshake->onReadable()) {
                    $this->state = self::STATE_OK;
                    $this->onStateOk();
                }
            }
            elseif ($this->isOk()) {
                $this->frameHandler->onReadable();
            }
        }
        catch (WebSocketError $e) {
            $this->close($e->getCode(), $e->getMessage());
            throw $e;
        }
        catch (Throwable $e) {
            $this->close(Frame::CLOSE_INTERNAL_ERROR);
            throw $e;
        }
    }

    /**
     * Called when the initial connection handshake succeeds and frame I/O can occur.
     *
     * Does nothing by default.
     *
     * If you have negotiated an extension during {@link Handshake},
     * claim the RSV bits here via {@link FrameReader::setRsv()}
     */
    protected function onStateOk (): void {
        // stub
    }

    /**
     * Called when a complete `TEXT` payload is received from the peer.
     *
     * Throws by default.
     *
     * @param string $text
     * @throws WebSocketError
     */
    public function onText (string $text): void {
        unset($text);
        throw new WebSocketError(Frame::CLOSE_UNHANDLED_DATA, "I don't handle text.");
    }

    /**
     * Forwards to the {@link FrameHandler}
     *
     * @param string $binary
     */
    public function writeBinary (string $binary): void {
        $this->frameHandler->writeBinary($binary);
    }

    /**
     * Forwards to the {@link FrameHandler}
     *
     * @param string $text
     */
    public function writeText (string $text): void {
        $this->frameHandler->writeText($text);
    }

}