<?php

namespace Helix\Socket\WebSocket;

use LogicException;

/**
 * Interprets parsed frames from the peer, and packs and writes frames.
 */
class FrameHandler {

    /**
     * The `DATA` message buffer.
     *
     * @var string
     */
    protected $buffer = '';

    /**
     * @var WebSocketClient
     */
    protected $client;

    /**
     * Resume opCode for the `CONTINUE` handler.
     *
     * @var int|null
     */
    protected $continue;

    /**
     * Max outgoing fragment size.
     *
     * Each browser has its own standard, so this is generalized.
     *
     * Defaults to 128 KiB.
     *
     * @var int
     */
    protected $fragmentSize = 128 * 1024;

    /**
     * Maximum inbound message length.
     *
     * Defaults to 10 MiB.
     *
     * @var int
     */
    protected $maxLength = 10 * 1024 * 1024;

    /**
     * @param WebSocketClient $client
     */
    public function __construct (WebSocketClient $client) {
        $this->client = $client;
    }

    /**
     * @return int
     */
    public function getFragmentSize (): int {
        return $this->fragmentSize;
    }

    /**
     * @return int
     */
    public function getMaxLength (): int {
        return $this->maxLength;
    }

    /**
     * Progressively receives `BINARY` data into the buffer until the payload is complete.
     * Passes the complete payload up to {@link WebSocketClient::onBinary()}
     *
     * @param Frame $frame
     * @throws WebSocketError
     */
    protected function onBinary (Frame $frame): void {
        $this->buffer .= $frame->getPayload();
        if ($frame->isFinal()) {
            $binary = $this->buffer;
            $this->buffer = '';
            $this->client->onBinary($binary);
        }
    }

    /**
     * When a `CLOSE` frame is received. Calls {@link WebSocketClient::onClose()}
     *
     * https://tools.ietf.org/html/rfc6455#section-5.5.1
     * > If an endpoint receives a Close frame and did not previously send a
     * > Close frame, the endpoint MUST send a Close frame in response.  (When
     * > sending a Close frame in response, the endpoint typically echos the
     * > status code it received.)
     *
     * @param Frame $frame
     */
    protected function onClose (Frame $frame): void {
        $this->client->onClose($frame->getCloseCode(), $frame->getCloseReason());
    }

    /**
     * When a `CONTINUE` frame (data fragment) is received.
     *
     * @param Frame $frame
     * @throws WebSocketError
     */
    protected function onContinue (Frame $frame): void {
        if (!$this->continue) {
            throw new WebSocketError(
                Frame::CLOSE_PROTOCOL_ERROR,
                "Received CONTINUE without a prior fragment.",
                $frame
            );
        }
        try {
            if ($this->continue === Frame::OP_TEXT) {
                $this->onText($frame);
            }
            else {
                $this->onBinary($frame);
            }
        }
        finally {
            if ($frame->isFinal()) {
                $this->continue = null;
            }
        }
    }

    /**
     * When a control frame is received.
     *
     * https://tools.ietf.org/html/rfc6455#section-5.4
     * > Control frames (see Section 5.5) MAY be injected in the middle of
     * > a fragmented message.
     *
     * @param Frame $frame
     */
    protected function onControl (Frame $frame): void {
        if ($frame->isClose()) {
            $this->onClose($frame);
        }
        elseif ($frame->isPing()) {
            $this->onPing($frame);
        }
        elseif ($frame->isPong()) {
            $this->onPong($frame);
        }
        else {
            throw new WebSocketError(Frame::CLOSE_PROTOCOL_ERROR, "Unsupported control frame.", $frame);
        }
    }

    /**
     * When an initial data frame (not `CONTINUE`) is received.
     *
     * @param Frame $frame
     */
    protected function onData (Frame $frame): void {
        $this->onData_SetContinue($frame);
        if ($frame->isText()) {
            $this->onText($frame);
        }
        elseif ($frame->isBinary()) {
            $this->onBinary($frame);
        }
    }

    /**
     * Flags that we are expecting more data of the same type.
     *
     * @param Frame $frame
     * @throws WebSocketError
     */
    protected function onData_SetContinue (Frame $frame): void {
        if ($this->continue) {
            throw new WebSocketError(
                Frame::CLOSE_PROTOCOL_ERROR,
                "Received interleaved {$frame->getName()} against existing " . Frame::NAMES[$this->continue],
                $frame
            );
        }
        if (!$frame->isFinal()) {
            $this->continue = $frame->getOpCode();
        }
    }

    /**
     * Called by {@link WebSocketClient} when a complete frame has been received.
     *
     * Delegates to the other handler methods using the control flow outlined in the RFC.
     *
     * Eventually calls back to the {@link WebSocketClient} when payloads are complete.
     *
     * @param Frame $frame
     */
    public function onFrame (Frame $frame): void {
        $this->onFrame_CheckRsv($frame);
        $this->onFrame_CheckLength($frame);
        if ($frame->isControl()) {
            $this->onControl($frame);
        }
        elseif ($frame->isContinue()) {
            $this->onContinue($frame);
        }
        else {
            $this->onData($frame);
        }
    }

    /**
     * Validates the frame length.
     *
     * @param Frame $frame
     * @throws WebSocketError
     */
    protected function onFrame_CheckLength (Frame $frame): void {
        if ($frame->isData()) {
            $length = strlen($this->buffer);
            if ($length + $frame->getLength() > $this->maxLength) {
                throw new WebSocketError(
                    Frame::CLOSE_TOO_LARGE,
                    "Message would exceed {$this->maxLength} bytes",
                    $frame
                );
            }
        }
    }

    /**
     * Validates the frame's RSV bits against the initial connection handshake.
     *
     * @param Frame $frame
     * @throws WebSocketError
     */
    protected function onFrame_CheckRsv (Frame $frame): void {
        if ($badRsv = $frame->getRsv() & ~$this->client->getHandshake()->getRsv()) {
            $badRsv = str_pad(base_convert($badRsv >> 4, 10, 2), 3, '0', STR_PAD_LEFT);
            throw new WebSocketError(
                Frame::CLOSE_PROTOCOL_ERROR,
                "Received unknown RSV bits: 0b{$badRsv}",
                $frame
            );
        }
    }

    /**
     * When a `PING` is received. Calls {@link WebSocketClient::onPing()}
     *
     * @param Frame $frame
     */
    protected function onPing (Frame $frame): void {
        $this->client->onPing($frame->getPayload());
    }

    /**
     * When a `PONG` is received. Calls {@link WebSocketClient::onPong()}
     *
     * @param Frame $frame
     */
    protected function onPong (Frame $frame): void {
        $this->client->onPong($frame->getPayload());
    }

    /**
     * Progressively receives `TEXT` data until the payload is complete.
     * Validates the complete payload as UTF-8 and passes it up to {@link WebSocketClient::onText()}
     *
     * @param Frame $frame
     * @throws WebSocketError
     */
    protected function onText (Frame $frame): void {
        $this->buffer .= $frame->getPayload();
        if ($frame->isFinal()) {
            if (!mb_detect_encoding($this->buffer, 'UTF-8', true)) {
                throw new WebSocketError(Frame::CLOSE_BAD_DATA, "The received TEXT is not UTF-8.");
            }
            $text = $this->buffer;
            $this->buffer = '';
            $this->client->onText($text);
        }
    }

    /**
     * @param int $bytes
     * @return $this
     */
    public function setFragmentSize (int $bytes) {
        $this->fragmentSize = $bytes;
        return $this;
    }

    /**
     * @param int $bytes
     * @return $this
     */
    public function setMaxLength (int $bytes) {
        $this->maxLength = $bytes;
        return $this;
    }

    /**
     * Sends a payload to the peer, fragmenting if needed.
     *
     * @param int $opCode
     * @param string $payload
     */
    public function write (int $opCode, string $payload): void {
        $offset = 0;
        $total = strlen($payload);
        do {
            $fragment = substr($payload, $offset, $this->fragmentSize);
            if ($offset) {
                $opCode = Frame::OP_CONTINUE;
            }
            $offset += strlen($fragment);
            $this->writeFrame($offset >= $total, $opCode, $fragment);
        } while ($offset < $total);
    }

    /**
     * @param string $payload
     */
    public function writeBinary (string $payload): void {
        $this->write(Frame::OP_BINARY, $payload);
    }

    /**
     * @param int $code
     * @param string $reason
     */
    public function writeClose (int $code = Frame::CLOSE_NORMAL, string $reason = ''): void {
        $this->writeFrame(true, Frame::OP_CLOSE, pack('n', $code) . $reason);
    }

    /**
     * Writes a single frame.
     *
     * @param bool $final
     * @param int $opCode
     * @param string $payload
     */
    protected function writeFrame (bool $final, int $opCode, string $payload): void {
        if ($opCode & 0x08 and !$final) {
            throw new LogicException("Would have sent a fragmented control frame ({$opCode}) {$payload}");
        }
        $head = chr($final ? 0x80 | $opCode : $opCode);
        $length = strlen($payload);
        if ($length > 65535) {
            $head .= chr(127);
            $head .= pack('J', $length);
        }
        elseif ($length >= 126) {
            $head .= chr(126);
            $head .= pack('n', $length);
        }
        else {
            $head .= chr($length);
        }
        $this->client->write($head . $payload);
    }

    /**
     * @param string $payload
     */
    public function writePing (string $payload = ''): void {
        $this->writeFrame(true, Frame::OP_PING, $payload);
    }

    /**
     * @param string $payload
     */
    public function writePong (string $payload = ''): void {
        $this->writeFrame(true, Frame::OP_PONG, $payload);
    }

    /**
     * @param string $payload
     */
    public function writeText (string $payload): void {
        $this->write(Frame::OP_TEXT, $payload);
    }
}