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
use Helix\Socket\WebSocket\MessageHandler;
use Helix\Socket\WebSocket\WebSocketClient;
use Helix\Socket\WebSocket\WebSocketServer;

class ChatServer extends WebSocketServer {

    public function accept () {
        /** @var ChatClient $client */
        $client = parent::accept();
        echo "Connected: {$client}\n\n";
        return $client;
    }

    public function broadcastUserList () {
        $users = array_column($this->getClients(), 'nick');
        $count = count($users);
        sort($users);
        $users = implode(', ', $users);
        $this->broadcastText("[sys] Users ({$count}): {$users}");
    }

    public function close (int $code = Frame::CLOSE_INTERRUPT, $reason = '') {
        echo "Shutting down!\n\n";
        $this->broadcastText('[sys] Shutting down!');
        return parent::close($code, $reason);
    }

    protected function newClient ($resource) {
        return new ChatClient($resource, $this);
    }

    /**
     * @param ChatClient $client
     */
    public function remove ($client) {
        parent::remove($client);
        echo "Removed: {$client}\n\n";
        $this->broadcastText("[sys] {$client->nick} has left.");
        $this->broadcastUserList();
    }
}

class ChatClient extends WebSocketClient {

    /** @var string */
    public $nick;

    /** @var ChatServer */
    protected $server;

    public function __construct ($resource, ChatServer $server) {
        parent::__construct($resource, $server);
        $this->nick = $this->getPeerName()[1];
        $this->frameHandler = new FrameDebug($this);
        $this->messageHandler = new ChatHandler($this);
    }

    public function close (int $code = null, string $reason = '') {
        echo "Closing: {$this}\n\n";
        parent::close($code, $reason);
    }

    protected function onStateOk (): void {
        echo "Handshake Successful: {$this}\n\n";
        $this->getFrameHandler()->writeText("[sys] Your nick is {$this->nick}");
        $this->server->broadcastText("[sys] {$this->nick} has joined.");
        $this->server->broadcastUserList();
    }

}

class FrameDebug extends FrameHandler {

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

class ChatHandler extends MessageHandler {

    /** @var ChatClient */
    protected $client;

    public function onText (string $text): void {
        $this->client->getServer()->broadcastText("[{$this->client->nick}] {$text}");
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
