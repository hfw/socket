<?php

namespace Helix\Socket\WebSocket;

/**
 * Handles received frames from the peer, and packs and sends frames.
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
     * When a `BINARY` frame is received.
     *
     * Throws by default.
     *
     * @param Frame $binary
     * @throws WebSocketError
     */
    protected function onBinary (Frame $binary): void {
        $this->buffer .= $binary->getPayload();
        if ($binary->isFinal()) {
            $message = $this->buffer;
            $this->buffer = '';
            $this->client->getMessageHandler()->onBinary($message);
        }
    }

    /**
     * When a `CLOSE` frame is received.
     *
     * https://tools.ietf.org/html/rfc6455#section-5.5.1
     * > If an endpoint receives a Close frame and did not previously send a
     * > Close frame, the endpoint MUST send a Close frame in response.  (When
     * > sending a Close frame in response, the endpoint typically echos the
     * > status code it received.)
     *
     * @param Frame $close
     */
    protected function onClose (Frame $close): void {
        $this->client->close($close->getCloseCode());
    }

    /**
     * When a `CONTINUE` data fragment is received.
     *
     * @param Frame $frame
     * @throws WebSocketError
     */
    protected function onContinue (Frame $frame): void {
        try {
            switch ($this->continue) {
                case Frame::OP_TEXT:
                    $this->onText($frame);
                    break;
                case Frame::OP_BINARY:
                    $this->onBinary($frame);
                    break;
                default:
                    throw new WebSocketError(
                        Frame::CLOSE_PROTOCOL_ERROR,
                        "Received CONTINUE without a prior fragment.",
                        $frame
                    );
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
     * @param Frame $control
     */
    protected function onControl (Frame $control): void {
        if ($control->isClose()) {
            $this->onClose($control);
        }
        elseif ($control->isPing()) {
            $this->onPing($control);
        }
        elseif ($control->isPong()) {
            $this->onPong($control);
        }
    }

    /**
     * When an initial data frame (not `CONTINUE`) is received.
     *
     * @param Frame $data
     */
    protected function onData (Frame $data): void {
        $this->onData_SetContinue($data);
        if ($data->isText()) {
            $this->onText($data);
        }
        elseif ($data->isBinary()) {
            $this->onBinary($data);
        }
    }

    protected function onData_SetContinue (Frame $data): void {
        if ($this->continue) {
            $existing = Frame::NAMES[$this->continue];
            throw new WebSocketError(
                Frame::CLOSE_PROTOCOL_ERROR,
                "Received interleaved {$data->getName()} against existing {$existing}",
                $data
            );
        }
        if (!$data->isFinal()) {
            $this->continue = $data->getOpCode();
        }
    }

    /**
     * Invoked by the client when a complete frame has been received.
     *
     * Delegates to the other handler methods using the control flow outlined in the RFC.
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
     * @param Frame $frame
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
     * Throws if unknown RSV bits are received.
     *
     * @param Frame $frame
     * @throws WebSocketError
     */
    protected function onFrame_CheckRsv (Frame $frame): void {
        if ($badRsv = $frame->getRsv() & ~$this->client->getHandshake()->getRsv()) {
            $badRsv = str_pad(base_convert($badRsv >> 4, 10, 2), 3, '0', STR_PAD_LEFT);
            throw new WebSocketError(Frame::CLOSE_PROTOCOL_ERROR, "Received unknown RSV bits: 0b{$badRsv}");
        }
    }

    /**
     * When a `PING` frame is received.
     *
     * Automatically pongs the payload back by default.
     *
     * @param Frame $ping
     */
    protected function onPing (Frame $ping): void {
        $this->writePong($ping->getPayload());
    }

    /**
     * When a `PONG` frame is received.
     *
     * Does nothing by default.
     *
     * @param Frame $pong
     */
    protected function onPong (Frame $pong): void {
        // stub
    }

    /**
     * When a `TEXT` frame is received.
     *
     * Throws by default.
     *
     * @param Frame $text
     * @throws WebSocketError
     */
    protected function onText (Frame $text): void {
        $this->buffer .= $text->getPayload();
        if ($text->isFinal()) {
            $message = $this->buffer;
            $this->buffer = '';
            $this->client->getMessageHandler()->onText($message);
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
     * Fragments data into frames and writes them to the peer.
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
            throw new WebSocketError(
                Frame::CLOSE_INTERNAL_ERROR,
                "Would have sent a fragmented control frame ({$opCode}) {$payload}"
            );
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