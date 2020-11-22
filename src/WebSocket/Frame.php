<?php

namespace Helix\Socket\WebSocket;

/**
 * A WebSocket frame.
 *
 * @see https://tools.ietf.org/html/rfc6455#section-5.2 Base Framing Protocol
 * @see https://tools.ietf.org/html/rfc6455#section-7.4.1 Defined Status Codes
 */
class Frame {

    const RSV123 = 0x70;
    const RSV1 = 0x40;
    const RSV2 = 0x20;
    const RSV3 = 0x10;

    const OP_CONTINUE = 0x00;
    const OP_TEXT = 0x01;
    const OP_BINARY = 0x02;
    const OP_CLOSE = 0x08;
    const OP_PING = 0x09;
    const OP_PONG = 0x0a;

    const NAMES = [
        self::OP_CONTINUE => 'CONTINUE',
        self::OP_TEXT => 'TEXT',
        self::OP_BINARY => 'BINARY',
        0x03 => 'RESERVED DATA 0x03',
        0x04 => 'RESERVED DATA 0x04',
        0x05 => 'RESERVED DATA 0x05',
        0x06 => 'RESERVED DATA 0x06',
        0x07 => 'RESERVED DATA 0x07',
        self::OP_CLOSE => 'CLOSE',
        self::OP_PING => 'PING',
        self::OP_PONG => 'PONG',
        0x0b => 'RESERVED CONTROL 0x0b',
        0x0c => 'RESERVED CONTROL 0x0c',
        0x0d => 'RESERVED CONTROL 0x0d',
        0x0e => 'RESERVED CONTROL 0x0e',
        0x0f => 'RESERVED CONTROL 0x0f',
    ];

    const CLOSE_NORMAL = 1000;              // mutual closure
    const CLOSE_INTERRUPT = 1001;           // abrupt closure due to hangups, reboots, "going away"
    const CLOSE_PROTOCOL_ERROR = 1002;      // invalid behavior / framing
    const CLOSE_UNHANDLED_DATA = 1003;      // message handler doesn't want the payload
    const CLOSE_BAD_DATA = 1007;            // message handler can't understand the payload
    const CLOSE_POLICY_VIOLATION = 1008;    // generic "access denied"
    const CLOSE_TOO_LARGE = 1009;           // unacceptable payload size
    const CLOSE_EXPECTATION = 1010;         // peer closed because it wants extensions (listed in the reason)
    const CLOSE_INTERNAL_ERROR = 1011;      // like http 500

    /**
     * @var bool
     */
    protected $final;

    /**
     * @var int
     */
    protected $opCode;

    /**
     * @var string
     */
    protected $payload;

    /**
     * The RSV bits masked out of the first byte of the frame, as-is.
     *
     * @var int
     */
    protected $rsv;

    /**
     * @param bool $final
     * @param int $rsv
     * @param int $opCode
     * @param string $payload
     */
    public function __construct (bool $final, int $rsv, int $opCode, string $payload) {
        $this->final = $final;
        $this->rsv = $rsv;
        $this->opCode = $opCode;
        $this->payload = $payload;
    }

    /**
     * The payload, or `CLOSE` reason.
     *
     * @return string
     */
    public function __toString () {
        if ($this->isClose()) {
            return $this->getCloseReason();
        }
        return $this->payload;
    }

    /**
     * The `CLOSE` code.
     *
     * @see https://tools.ietf.org/html/rfc6455#section-5.5.1
     *
     * @return int
     */
    final public function getCloseCode (): int {
        $code = substr($this->payload, 0, 2);
        return isset($code[1]) ? unpack('n', $code)[1] : self::CLOSE_NORMAL;
    }

    /**
     * The `CLOSE` reason.
     *
     * @see https://tools.ietf.org/html/rfc6455#section-5.5.1
     *
     * @return string
     */
    final public function getCloseReason (): string {
        return substr($this->payload, 2);
    }

    /**
     * @return int
     */
    final public function getLength (): int {
        return strlen($this->payload);
    }

    /**
     * @return string
     */
    public function getName (): string {
        return self::NAMES[$this->opCode];
    }

    /**
     * @return int
     */
    final public function getOpCode (): int {
        return $this->opCode;
    }

    /**
     * @return string
     */
    final public function getPayload (): string {
        return $this->payload;
    }

    /**
     * @return int
     */
    final public function getRsv (): int {
        return $this->rsv;
    }

    /**
     * @return bool
     */
    final public function hasRsv1 (): bool {
        return (bool)($this->rsv & self::RSV1);
    }

    /**
     * @return bool
     */
    final public function hasRsv2 (): bool {
        return (bool)($this->rsv & self::RSV2);
    }

    /**
     * @return bool
     */
    final public function hasRsv3 (): bool {
        return (bool)($this->rsv & self::RSV3);
    }

    /**
     * @return bool
     */
    final public function isBinary (): bool {
        return $this->opCode === self::OP_BINARY;
    }

    /**
     * @return bool
     */
    final public function isClose (): bool {
        return $this->opCode === self::OP_CLOSE;
    }

    /**
     * @return bool
     */
    final public function isContinue (): bool {
        return $this->opCode === self::OP_CONTINUE;
    }

    /**
     * @return bool
     */
    final public function isControl (): bool {
        return $this->opCode >= self::OP_CLOSE;
    }

    /**
     * @return bool
     */
    final public function isData (): bool {
        return $this->opCode < self::OP_CLOSE;
    }

    /**
     * @return bool
     */
    final public function isFinal (): bool {
        return $this->final;
    }

    /**
     * @return bool
     */
    final public function isPing (): bool {
        return $this->opCode === self::OP_PING;
    }

    /**
     * @return bool
     */
    final public function isPong (): bool {
        return $this->opCode === self::OP_PONG;
    }

    /**
     * @return bool
     */
    final public function isText (): bool {
        return $this->opCode === self::OP_TEXT;
    }

}