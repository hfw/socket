<?php

namespace Helix\Socket\WebSocket;

use RuntimeException;
use Throwable;

/**
 * A WebSocket error.
 *
 * Error codes less than `1000` are not used by the protocol or exposed to peers,
 * but may be used internally per implementation.
 *
 * @see https://tools.ietf.org/html/rfc6455#section-7.4
 */
class WebSocketError extends RuntimeException {

    /**
     * @var mixed
     */
    protected $extra;

    /**
     * @var Frame|null
     */
    protected $frame;

    public function __construct (int $code, string $message = '', Frame $frame = null, Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->frame = $frame;
    }

    /**
     * @return mixed
     */
    public function getExtra () {
        return $this->extra;
    }

    /**
     * @return Frame|null
     */
    public function getFrame () {
        return $this->frame;
    }

    /**
     * @param mixed $extra
     * @return $this
     */
    public function setExtra ($extra) {
        $this->extra = $extra;
        return $this;
    }

}