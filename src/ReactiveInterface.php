<?php

namespace Helix\Socket;

/**
 * The instance can be added to a reactor and notified of selection.
 *
 * @see Reactor
 */
interface ReactiveInterface extends SocketInterface {

    /**
     * Called by the reactor when the socket has readable out-of-band data.
     *
     * @return void
     */
    public function onOutOfBand (): void;

    /**
     * Called by the reactor when the socket has readable data.
     *
     * @return void
     */
    public function onReadable (): void;

}