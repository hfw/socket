<?php

namespace Helix\Socket\WebSocket;

/**
 * WebSocket handshake.
 */
class HandShake {

    const RFC_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * @var string
     */
    protected $buffer = '';

    /**
     * @var WebSocketClient
     */
    protected $client;

    /**
     * @var string[]
     */
    protected $headers = [];

    /**
     * @var string
     */
    protected $method;

    /**
     * Claimed RSV bit mask.
     *
     * @var int
     */
    protected $rsv = 0;

    /**
     * Received handshake size limit.
     *
     * The connection is closed (HTTP 413) if the received headers exceed this many bytes.
     *
     * @var int
     */
    protected $sizeLimit = 4096;

    /**
     * @param WebSocketClient $client
     */
    public function __construct (WebSocketClient $client) {
        $this->client = $client;
    }

    /**
     * @return string[]
     */
    public function getHeaders () {
        return $this->headers;
    }

    /**
     * @return string
     */
    public function getMethod (): string {
        return $this->method;
    }

    /**
     * @return int
     */
    public function getRsv (): int {
        return $this->rsv;
    }

    /**
     * Negotiates the initial connection.
     *
     * @return bool
     * @throws WebSocketError
     */
    public function negotiate (): bool {
        $this->buffer .= $this->client->recvAll();
        try {
            if (strlen($this->buffer) > $this->sizeLimit) {
                throw new WebSocketError(413, "{$this->client} exceeded the maximum handshake size.");
            }
            if (false === $end = strpos($this->buffer, "\r\n\r\n")) {
                return false;
            }
            $head = explode("\r\n", substr($this->buffer, 0, $end));
            $this->method = array_shift($head);
            foreach ($head as $header) {
                $header = explode(':', $header, 2);
                if (count($header) !== 2) {
                    throw new WebSocketError(400, "{$this->client} sent a malformed header.");
                }
                [$key, $value] = $header;
                $key = strtolower(trim($key));
                $value = trim($value);
                if (isset($this->headers[$key])) {
                    $this->headers[$key] .= ', ' . $value;
                }
                else {
                    $this->headers[$key] = $value;
                }
            }
            $this->buffer = '';
            $this->validate();
            $this->upgrade();
            $this->client->write("\r\n\r\n");
            return true;
        }
        catch (WebSocketError $e) {
            $this->client->write("HTTP/1.1 {$e->getCode()}\r\n\r\n");
            throw $e;
        }
    }

    /**
     * Sends the connection upgrade headers.
     */
    protected function upgrade (): void {
        $this->client->write(implode("\r\n", [
            "HTTP/1.1 101 Switching Protocols",
            "Connection: Upgrade",
            "Upgrade: websocket",
            "Sec-WebSocket-Accept: " . base64_encode(sha1($this->headers['sec-websocket-key'] . self::RFC_GUID, true)),
        ]));
    }

    /**
     * Validates the received HTTP handshake headers, or throws.
     *
     * @throws WebSocketError
     */
    protected function validate (): void {
        if (!(
            $check = 'method = http 1.1'
            and preg_match('/HTTP\/1\.1$/i', $this->method)
            and $check = 'connection = upgrade'
            and preg_match('/^upgrade$/i', $this->headers['connection'] ?? '')
            and $check = 'upgrade = websocket'
            and preg_match('/^websocket$/i', $this->headers['upgrade'] ?? '')
            and $check = 'version = 13'
            and ($this->headers['sec-websocket-version'] ?? '') === '13'
            and $check = 'key length = 16'
            and strlen(base64_decode($this->headers['sec-websocket-key'] ?? '')) === 16
        )) {
            throw new WebSocketError(400, "Handshake with {$this->client} failed on validation: {$check}");
        }
    }
}