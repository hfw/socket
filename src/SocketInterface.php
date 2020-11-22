<?php

namespace Helix\Socket;

/**
 * Instance has an underlying socket resource.
 */
interface SocketInterface {

    /**
     * Read channel.
     */
    const CH_READ = 0;

    /**
     * Write channel.
     */
    const CH_WRITE = 1;

    /**
     * Out-of-band channel.
     */
    const CH_EXCEPT = 2;

    /**
     * Returns the underlying socket resource as an integer.
     *
     * @return int
     */
    public function getId (): int;

    /**
     * Returns the underlying socket resource.
     *
     * @return resource
     */
    public function getResource ();

    /**
     * Whether the underlying resource is usable.
     *
     * @return bool
     */
    public function isOpen (): bool;

}