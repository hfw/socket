<?php

namespace Helix\Socket\WebSocket;

use Generator;
use InvalidArgumentException;

/**
 * Reads frames from the peer.
 */
class FrameReader {

    // todo? doesn't allow unmasked frames.
    //                         op((char     )|(short     )|(bigint      ))(mask       )
    protected const REGEXP = '/^.([\x80-\xfd]|\xfe(?<n>..)|\xff(?<J>.{8}))(?<mask>.{4})/s';

    const MAX_LENGTH_RANGE = [125, 2 ** 63 - 1];

    /**
     * @var string
     */
    protected $buffer = '';

    /**
     * @var WebSocketClient
     */
    protected $client;

    /**
     * @var array
     */
    protected $head = [];

    /**
     * Payload size limit.
     *
     * Must fall within {@link MAX_LENGTH_RANGE} (inclusive).
     *
     * Defaults to 10 MiB.
     *
     * https://tools.ietf.org/html/rfc6455#section-5.2
     * > ... interpreted as a 64-bit unsigned integer (the
     * > most significant bit MUST be 0) ...
     *
     * @var int
     */
    protected $maxLength = 10 * 1024 * 1024;

    public function __construct (WebSocketClient $client) {
        $this->client = $client;
    }

    /**
     * @return Frame|null
     */
    protected function getFrame () {
        if (!$this->head) {
            if (preg_match(self::REGEXP, $this->buffer, $head)) {
                [, $op, $len] = unpack('C2', $head[0]);
                $len = [0xfe => 'n', 0xff => 'J'][$len] ?? ($len & 0x7f);
                $this->head = [
                    'final' => $op & 0x80,
                    'rsv' => $op & Frame::RSV123,
                    'opCode' => $op & 0x0f,
                    'length' => is_int($len) ? $len : unpack($len, $head[$len])[1],
                    'mask' => array_values(unpack('C*', $head['mask'])),
                ];
                $this->buffer = substr($this->buffer, strlen($head[0]));
                $this->validate();
            }
            elseif (strlen($this->buffer) >= 14) { // max head room
                throw new WebSocketError(Frame::CLOSE_PROTOCOL_ERROR, 'Bad frame.');
            }
            else {
                return null;
            }
        }
        $length = $this->head['length'];
        if (strlen($this->buffer) >= $length) {
            $payload = substr($this->buffer, 0, $length);
            $this->buffer = substr($this->buffer, $length);
            $mask = $this->head['mask'];
            for ($i = 0; $i < $length; $i++) {
                $payload[$i] = chr(ord($payload[$i]) ^ $mask[$i % 4]);
            }
            $frame = new Frame($this->head['final'], $this->head['rsv'], $this->head['opCode'], $payload);
            $this->head = [];
            return $frame;
        }
        return null;
    }

    /**
     * Constructs and yields all available frames from the peer.
     *
     * @return Generator|Frame[]
     */
    public function getFrames () {
        $this->buffer .= $this->client->recvAll();
        while ($frame = $this->getFrame()) {
            yield $frame;
        }
    }

    /**
     * @return int
     */
    public function getMaxLength (): int {
        return $this->maxLength;
    }

    /**
     * @param int $bytes
     * @return $this
     */
    public function setMaxLength (int $bytes) {
        if ($bytes < self::MAX_LENGTH_RANGE[0] or $bytes > self::MAX_LENGTH_RANGE[1]) {
            throw new InvalidArgumentException('Max length must be within range [125,2^63-1]');
        }
        $this->maxLength = $bytes;
        return $this;
    }

    /**
     * Validates the current head by not throwing.
     *
     * @throws WebSocketError
     */
    protected function validate (): void {
        if ($this->head['length'] > $this->maxLength) {
            throw new WebSocketError(Frame::CLOSE_TOO_LARGE, "Payload would exceed {$this->maxLength} bytes");
        }
        $opCode = $this->head['opCode'];
        $name = Frame::NAMES[$opCode];
        if ($opCode & 0x08) { // control
            if ($opCode > Frame::OP_PONG) {
                throw new WebSocketError(Frame::CLOSE_PROTOCOL_ERROR, "Received {$name}");
            }
            if (!$this->head['final']) {
                throw new WebSocketError(Frame::CLOSE_PROTOCOL_ERROR, "Received fragmented {$name}");
            }
        }
        elseif ($opCode > Frame::OP_BINARY) { // data
            throw new WebSocketError(Frame::CLOSE_PROTOCOL_ERROR, "Received {$name}");
        }
    }
}