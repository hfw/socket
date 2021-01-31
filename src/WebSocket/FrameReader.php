<?php

namespace Helix\Socket\WebSocket;

use Generator;

/**
 * Reads frames from the peer.
 *
 * https://tools.ietf.org/html/rfc6455#section-5
 *
 * TODO: Support unmasked frames.
 */
class FrameReader {

    /**
     * https://tools.ietf.org/html/rfc6455#section-5.2
     */
    protected const REGEXP =
        //op((char     )|(short     )|(bigint      ))(mask       )
        '/^.([\x80-\xfd]|\xfe(?<n>..)|\xff(?<J>.{8}))(?<mask>.{4})/s';

    /**
     * Peer read buffer.
     *
     * @var string
     */
    protected $buffer = '';

    /**
     * @var WebSocketClient
     */
    protected $client;

    /**
     * Frame header buffer.
     *
     * @var null|array
     */
    protected $header;

    /**
     * Maximum inbound per-frame payload length (fragment).
     *
     * Must be greater than or equal to `125`
     *
     * Defaults to 128 KiB.
     *
     * https://tools.ietf.org/html/rfc6455#section-5.2
     *
     * @var int
     */
    protected $maxLength = 128 * 1024;

    /**
     * RSV bit mask claimed by extensions.
     *
     * @var int
     */
    protected $rsv = 0;

    /**
     * @param WebSocketClient $client
     */
    public function __construct (WebSocketClient $client) {
        $this->client = $client;
    }

    /**
     * Reads and returns a single pending frame from the buffer, or nothing.
     *
     * @return null|Frame
     * @throws WebSocketError
     */
    protected function getFrame (): ?Frame {
        // wait for the header
        if (!$this->header ??= $this->getFrame_header()) {
            return null;
        }

        // wait for the whole frame
        $length = $this->header['length'];
        if (strlen($this->buffer) < $length) {
            return null;
        }

        // extract the payload
        $payload = substr($this->buffer, 0, $length);

        // chop the buffer
        $this->buffer = substr($this->buffer, $length);

        // unmask the payload
        $mask = $this->header['mask'];
        for ($i = 0; $i < $length; $i++) {
            $payload[$i] = chr(ord($payload[$i]) ^ $mask[$i % 4]);
        }

        // construct the frame instance
        $frame = $this->newFrame($payload);

        // destroy the header buffer
        $this->header = null;

        // return the frame
        return $frame;
    }

    /**
     * https://tools.ietf.org/html/rfc6455#section-5.2
     * @return null|array
     */
    protected function getFrame_header (): ?array {
        if (!preg_match(self::REGEXP, $this->buffer, $match)) {
            if (strlen($this->buffer) >= 14) { // max head room
                throw new WebSocketError(Frame::CLOSE_PROTOCOL_ERROR, 'Bad frame.');
            }
            return null;
        }

        // unpack the first two bytes
        [, $b0, $b1] = unpack('C2', $match[0]); // 1-based indices

        // convert the second byte into an unpack() format, or the actual length (sans the MASK bit).
        // the unpack() format is also used as the length's named-group in the regexp match.
        $len = [0xfe => 'n', 0xff => 'J'][$b1] ?? ($b1 & Frame::LEN);

        // fill the header buffer
        $header = [
            'final' => $final = $b0 & Frame::FIN,
            'rsv' => $rsv = $b0 & Frame::RSV123,
            'opCode' => $opCode = $b0 & Frame::OP,
            'length' => $length = is_int($len) ? $len : unpack($len, $match[$len])[1],
            'mask' => array_values(unpack('C*', $match['mask'])),
        ];

        // chop the peer buffer
        $this->buffer = substr($this->buffer, strlen($match[0]));

        // validate
        if ($badRsv = $rsv & ~$this->rsv) {
            $badRsv = str_pad(base_convert($badRsv >> 4, 10, 2), 3, '0', STR_PAD_LEFT);
            throw new WebSocketError(Frame::CLOSE_PROTOCOL_ERROR, "Received unknown RSV bits: 0b{$badRsv}");
        }
        elseif ($opCode >= Frame::OP_CLOSE) {
            if ($opCode > Frame::OP_PONG) {
                throw new WebSocketError(Frame::CLOSE_PROTOCOL_ERROR, "Received unsupported control frame ({$opCode})");
            }
            elseif (!$final) {
                throw new WebSocketError(Frame::CLOSE_PROTOCOL_ERROR, "Received fragmented control frame ({$opCode})");
            }
        }
        elseif ($opCode > Frame::OP_BINARY) {
            throw new WebSocketError(Frame::CLOSE_PROTOCOL_ERROR, "Received unsupported data frame ({$opCode})");
        }
        elseif ($length > $this->maxLength) {
            throw new WebSocketError(Frame::CLOSE_TOO_LARGE, "Payload would exceed {$this->maxLength} bytes");
        }

        // return the header
        return $header;
    }

    /**
     * Yields all available frames from the peer.
     *
     * @return Generator|Frame[]
     */
    public function getFrames () {
        // read into the buffer
        $this->buffer .= $bytes = $this->client->recvAll();

        // check for peer disconnection
        if (!strlen($bytes)) {
            $this->client->close();
        }

        // yield frames
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
     * @return int
     */
    public function getRsv (): int {
        return $this->rsv;
    }

    /**
     * {@link Frame} factory.
     *
     * @param string $payload
     * @return Frame
     */
    protected function newFrame (string $payload): Frame {
        return new Frame($this->header['final'], $this->header['rsv'], $this->header['opCode'], $payload);
    }

    /**
     * @param int $bytes
     * @return $this
     */
    public function setMaxLength (int $bytes) {
        $this->maxLength = min(max(125, $bytes), 2 ** 63 - 1);
        return $this;
    }

    /**
     * @param int $rsv
     * @return $this
     */
    public function setRsv (int $rsv) {
        $this->rsv = $rsv;
        return $this;
    }
}