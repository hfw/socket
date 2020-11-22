<?php

namespace Helix\Socket\Test;

error_reporting(E_ALL);

require_once '../vendor/autoload.php';

use Helix\Socket\SocketError;
use Helix\Socket\ReactiveInterface;
use Helix\Socket\Reactor;
use Helix\Socket\StreamServer;
use Helix\Socket\StreamClient;
use Helix\Socket\DatagramServer;

class Client extends StreamClient implements ReactiveInterface {

    /**
     * @var Reactor
     */
    protected $reactor;

    /**
     * @param $resource
     * @param Reactor $reactor
     */
    public function __construct ($resource, Reactor $reactor) {
        parent::__construct($resource);
        $this->reactor = $reactor;
        $this->reactor->add($this);
    }

    public function close () {
        $this->reactor->remove($this);
        return parent::close();
    }

    /**
     * @return void
     */
    public function onOutOfBand (): void {
    }

    /**
     * @return void
     * @throws SocketError
     */
    public function onReadable (): void {
        $data = $this->recv(256);
        if (!strlen($data)) {
            $this->close();
        }
        else {
            echo "Client {$this} said: {$data}\n";
            $this->send($data);
        }
    }
}

class Host {

    /**
     * @var UdpServer
     */
    protected $dgram;

    /**
     * @var TcpServer
     */
    protected $inet;

    /**
     * @var Reactor
     */
    protected $reactor;

    /**
     * @var TcpServer
     */
    protected $unix;

    /**
     * @throws SocketError
     */
    public function __construct () {
        $this->reactor = new Reactor();
        // inet
        $this->inet = TcpServer::create(AF_INET, $this->reactor)->bind('127.0.0.1')->listen();
        file_put_contents('server.tcp.port', $this->inet->getSockName()[1]);
        // dgram
        $this->dgram = UdpServer::create(AF_INET, $this->reactor)->bind('127.0.0.1');
        file_put_contents('server.udp.port', $this->dgram->getSockName()[1]);
        // unix
        @unlink('server.sock');
        $this->unix = TcpServer::create(AF_UNIX, $this->reactor)->bind('server.sock')->listen();
    }

    /**
     * @throws SocketError
     */
    public function serve () {
        while (true) {
            $this->reactor->react();
        }
    }
}

class TcpServer extends StreamServer implements ReactiveInterface {

    /**
     * @var Reactor
     */
    protected $reactor;

    public function __construct ($resource, Reactor $reactor) {
        parent::__construct($resource);
        $this->reactor = $reactor;
        $reactor->add($this);
    }

    public function accept () {
        $client = parent::accept();
        echo "TCP Server {$this} accepted connection from {$client}\n";
        return $client;
    }

    /**
     * @param resource $resource
     * @return Client
     * @throws SocketError
     */
    protected function newClient ($resource) {
        return new Client($resource, $this->reactor);
    }

    /**
     * @return void
     */
    public function onOutOfBand (): void {
    }

    /**
     * @return void
     * @throws SocketError
     */
    public function onReadable (): void {
        $this->accept();
    }

}

class UdpServer extends DatagramServer implements ReactiveInterface {

    /**
     * @var Reactor
     */
    protected $reactor;

    public function __construct ($resource, Reactor $reactor) {
        parent::__construct($resource);
        $this->reactor = $reactor;
        $reactor->add($this);
    }

    /**
     * @return void
     */
    public function onOutOfBand (): void {
    }

    /**
     * @return void
     * @throws SocketError
     */
    public function onReadable (): void {
        $data = $this->recv(256, 0, $name, $port);
        echo "UDP Server {$this}: Client {$name}:{$port} said {$data}\n";
    }

}

$host = new Host;
$host->serve();
