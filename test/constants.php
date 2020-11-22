<?php

// prints PHP's socket constants.

$constants = get_defined_constants(1);
$constants = $constants['sockets'];
ksort($constants);
array_walk($constants,function(&$v,$k){
    if (strpos($k,'SOCKET_E') === 0) {
        $v = $v.' '.socket_strerror($v);
    }
});
print_r($constants);
