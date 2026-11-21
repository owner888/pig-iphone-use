<?php

declare(strict_types=1);

/*
 * A stand-in for usbmuxd and, behind it, WDA: answers ListDevices with one USB iPhone, and a
 * Connect to port 8100 by becoming that port — an HTTP server that answers /status, /session and
 * /session/<id>/window/size. Anything else: Connect refused (3). Run by UsbmuxTest.
 */

require $argv[2];

$path = $argv[1];
@unlink($path);
$server = stream_socket_server('unix://' . $path, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "cannot listen: {$errstr}\n");
    exit(1);
}
echo "ready\n";

$reply = static function ($peer, array $message): void {
    $body = \PigIphoneUse\Plist::encode($message);
    fwrite($peer, pack('VVVV', 16 + strlen($body), 1, 8, 1) . $body);
};

while ($peer = @stream_socket_accept($server, 30)) {
    $header = fread($peer, 16);
    $length = unpack('Vlength', $header)['length'];
    $request = \PigIphoneUse\Plist::decode(stream_get_contents($peer, $length - 16));

    if ($request['MessageType'] === 'ListDevices') {
        $reply($peer, ['DeviceList' => [['DeviceID' => 42, 'Properties' => ['SerialNumber' => '00008110-FAKE', 'ConnectionType' => 'USB']]]]);
        fclose($peer);
        continue;
    }

    $port = (($request['PortNumber'] & 0xFF) << 8) | ($request['PortNumber'] >> 8);
    if ($request['MessageType'] !== 'Connect' || $request['DeviceID'] !== 42 || $port !== 8100) {
        $reply($peer, ['MessageType' => 'Result', 'Number' => 3]);
        fclose($peer);
        continue;
    }

    $reply($peer, ['MessageType' => 'Result', 'Number' => 0]);

    // Now the tunnel: one HTTP request.
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $head .= fread($peer, 1);
    }
    preg_match('/Content-Length: (\d+)/i', $head, $m);
    $body = isset($m[1]) ? fread($peer, (int) $m[1]) : '';
    [$method, $route] = explode(' ', $head);

    $value = match (true) {
        $route === '/status' => ['ready' => true, 'os' => ['version' => '18.1']],
        $method === 'POST' && $route === '/session' => null,
        str_ends_with($route, '/appium/settings') => null,
        str_ends_with($route, '/window/size') => ['width' => 393, 'height' => 852],
        default => ['error' => 'unknown command', 'message' => "no {$method} {$route}"],
    };
    $json = $route === '/session' ? ['sessionId' => 'S1', 'value' => ['sessionId' => 'S1']] : ['value' => $value];
    $out = json_encode($json);
    fwrite($peer, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($out) . "\r\nConnection: close\r\n\r\n" . $out);
    fclose($peer);
}
