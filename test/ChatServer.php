<?php

// WebSocket example host.
//
// Run:
// $ php ChatServer.php
//
// And open chat.html in a few browser tabs.

error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

use Helix\Socket\Reactor;
use Helix\Socket\WebSocket\Frame;
use Helix\Socket\WebSocket\FrameHandler;
use Helix\Socket\WebSocket\WebSocketClient;
use Helix\Socket\WebSocket\WebSocketServer;

class ChatClient extends WebSocketClient {

    /** @var string */
    public $nick;

    /** @var ChatServer */
    protected $server;

    /**
     * @param $resource
     * @param ChatServer $server
     */
    public function __construct ($resource, ChatServer $server) {
        parent::__construct($resource, $server);
        $this->nick = $this->getPeerName()[1];
        $this->frameHandler = new FrameDebug($this);
    }

    public function close (int $code = null, string $reason = '') {
        echo "Closing: {$this}\n\n";
        parent::close($code, $reason);
    }

    protected function onStateOk (): void {
        echo "Handshake Successful: {$this}\n\n";
        $this->frameHandler->writeText("[sys] Your nick is {$this->nick}");
        $this->server->broadcastText("[sys] {$this->nick} has joined.");
        $this->server->broadcastUserList();
    }

    public function onText (string $text): void {
        $this->server->broadcastText("[{$this->nick}] {$text}");
    }

}

class ChatServer extends WebSocketServer {

    /**
     * @return ChatClient
     */
    public function accept (): ChatClient {
        /** @var ChatClient $client */
        $client = parent::accept();
        echo "Connected: {$client}\n\n";
        return $client;
    }

    public function broadcastUserList (): void {
        $users = array_column($this->getClients(), 'nick');
        $count = count($users);
        sort($users);
        $users = implode(', ', $users);
        $this->broadcastText("[sys] Users ({$count}): {$users}");
    }

    /**
     * @param int $code
     * @param string $reason
     * @return $this
     */
    public function close (int $code = Frame::CLOSE_INTERRUPT, $reason = '') {
        echo "Shutting down!\n\n";
        $this->broadcastText('[sys] Shutting down!');
        return parent::close($code, $reason);
    }

    /**
     * @param resource $resource
     * @return ChatClient
     */
    protected function newClient ($resource): ChatClient {
        return new ChatClient($resource, $this);
    }

    /**
     * @param ChatClient $client
     */
    public function remove ($client): void {
        parent::remove($client);
        echo "Removed: {$client}\n\n";
        $this->broadcastText("[sys] {$client->nick} has left.");
        $this->broadcastUserList();
    }
}

class FrameDebug extends FrameHandler {

    /**
     * @param Frame $frame
     */
    public function onFrame (Frame $frame): void {
        echo "<< {$this->client} ";
        $length = $frame->getLength();
        $payload = $frame->getPayload();
        $payload = $length > 128 ? substr($payload, 0, 128) . '...' : $payload;
        print_r([
            'final' => (int)$frame->isFinal(),
            'opCode' => $frame->getOpCode(),
            'length' => $length,
            'close?' => $frame->isClose() ? $frame->getCloseCode() : 0,
            'payload' => $frame->isClose() ? $frame->getCloseReason() : $payload,
        ]);
        echo "\n";
        parent::onFrame($frame);
    }

    /**
     * @param bool $final
     * @param int $opCode
     * @param string $payload
     */
    protected function writeFrame (bool $final, int $opCode, string $payload): void {
        echo ">> {$this->client} ";
        $length = strlen($payload);
        print_r([
            'final' => (int)$final,
            'opCode' => $opCode,
            'length' => $length,
            'payload' => $length > 128 ? substr($payload, 0, 128) . '...' : $payload,
        ]);
        echo "\n";
        parent::writeFrame($final, $opCode, $payload);
    }
}

$reactor = new Reactor();
$server = ChatServer::create(AF_INET, $reactor)
    ->setOption(SO_REUSEADDR, 1)
    ->bind('127.0.0.1', 44444)
    ->listen();

echo "Listening on {$server}\n\n";

pcntl_signal(SIGINT, function() use ($server) {
    $server->close();
});

while ($reactor->count()) {
    try {
        if (!$reactor->react(10)) {
            $server->broadcastPing('poke');
        }
    }
    catch (RuntimeException $e) {
        echo "\n\n{$e}\n\n";
        pcntl_signal_dispatch();
    }
}
