<?php

namespace Helix\Socket\WebSocket;

/**
 * Handles complete payloads received from the peer.
 */
class MessageHandler {

    /**
     * @var WebSocketClient
     */
    protected $client;

    /**
     * @param WebSocketClient $client
     */
    public function __construct (WebSocketClient $client) {
        $this->client = $client;
    }

    /**
     * Throws by default.
     *
     * @param string $binary
     */
    public function onBinary (string $binary): void {
        unset($binary);
        throw new WebSocketError(Frame::CLOSE_UNHANDLED_DATA, "I don't handle binary data.");
    }

    /**
     * Throws by default.
     *
     * @param string $text
     */
    public function onText (string $text): void {
        $this->onText_CheckUtf8($text);
        throw new WebSocketError(Frame::CLOSE_UNHANDLED_DATA, "I don't handle text.");
    }

    /**
     * Throws if the payload isn't UTF-8.
     *
     * @param string $text
     */
    protected function onText_CheckUtf8 (string $text): void {
        if (!mb_detect_encoding($text, 'UTF-8', true)) {
            throw new WebSocketError(Frame::CLOSE_BAD_DATA, "Received TEXT is not UTF-8.");
        }
    }

}