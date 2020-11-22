<?php

error_reporting(E_ALL);

require '../vendor/autoload.php';

use Helix\Socket\StreamClient;
use Helix\Socket\DatagramClient;

$tcpPort = @intval(file_get_contents('server.tcp.port')) or die("Host.php INET TCP isn't listening.\n");
$udpPort = @intval(file_get_contents('server.udp.port')) or die("Host.php INET UDP isn't listening.\n");

try {
    while (true) {
        switch (rand(1, 3)) {
            case 1:
                echo "Saying hello to INET TCP...\n";
                $remote = StreamClient::create()->connect('127.0.0.1', $tcpPort);
                $remote->send("hello");
                echo "{$remote} said: {$remote->read(256)}\n";
                break;
            case 2:
                echo "Saying hello to INET UDP...\n";
                $remote = DatagramClient::create()->connect('127.0.0.1', $udpPort);
                $remote->send("hello");
                break;
            default:
                echo "Saying hello to UNIX TCP...\n";
                $remote = StreamClient::create(AF_UNIX)->connect('server.sock');
                $remote->send("hello");
                echo "{$remote} said: {$remote->read(256)}\n";
                break;
        }
        $remote->close();
        sleep(1);
    }
}
catch (Exception $exception) {
    echo $exception;
}