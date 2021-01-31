<?php

namespace Helix\Socket\WebSocket;

use Throwable;

/**
 * Initial WebSocket connection handshake.
 *
 * https://tools.ietf.org/html/rfc6455#section-1.3
 */
class Handshake {

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
     * The connection is closed (HTTP 413) if the received headers exceed this many bytes.
     *
     * @var int
     */
    protected $maxLength = 4096;

    /**
     * @var string
     */
    protected $method;

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
     * Negotiates the initial connection.
     *
     * @return bool
     * @throws WebSocketError
     * @throws Throwable
     */
    public function onReadable (): bool {
        // read into the buffer
        $this->buffer .= $bytes = $this->client->recvAll();

        // check for peer disconnection
        if (!strlen($bytes)) {
            $this->client->close();
            return false;
        }

        // read frames from the buffer and yield
        try {
            // length check
            if (strlen($this->buffer) > $this->maxLength) {
                throw new WebSocketError(413, "{$this->client} exceeded the maximum handshake size.");
            }
            // still reading?
            if (false === $end = strpos($this->buffer, "\r\n\r\n")) {
                return false;
            }
            // parse the headers
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
            $this->buffer = ''; // wipe the buffer
            $this->validate();
        }
        catch (WebSocketError $e) { // catch and respond with HTTP error and rethrow
            $this->client->write("HTTP/1.1 {$e->getCode()} WebSocket Handshake Failure\r\n\r\n");
            throw $e;
        }
        catch (Throwable $e) { // catch everything else and respond with HTTP 500 and rethrow
            $this->client->write("HTTP/1.1 500 WebSocket Internal Error\r\n\r\n");
            throw $e;
        }

        // send upgrade headers
        $this->upgrade();
        $this->client->write("\r\n\r\n");

        // success
        return true;
    }

    /**
     * Sends the connection upgrade headers.
     */
    protected function upgrade (): void {
        $key = base64_encode(sha1($this->headers['sec-websocket-key'] . self::RFC_GUID, true));
        $this->client->write(implode("\r\n", [
            "HTTP/1.1 101 Switching Protocols",
            "Connection: Upgrade",
            "Upgrade: websocket",
            "Sec-WebSocket-Accept: {$key}"
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