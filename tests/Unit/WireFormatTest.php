<?php

declare(strict_types=1);

namespace PigIphoneUse\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigIphoneUse\Plist;
use PigIphoneUse\Usbmux;
use PigIphoneUse\Wda;

/** What goes over the wire to usbmuxd and comes back from WDA. */
final class WireFormatTest extends TestCase
{
    public function testAPropertyListComesBackAsItWent(): void
    {
        $value = ['MessageType' => 'Connect', 'DeviceID' => 7, 'Flag' => true, 'List' => ['a', 'b <&>'], 'Inner' => ['Real' => 1.5]];

        $this->assertSame($value, Plist::decode(Plist::encode($value)));
    }

    public function testUsbmuxdsDeviceListIsRead(): void
    {
        $xml = Plist::encode(['DeviceList' => [
            ['DeviceID' => 3, 'MessageType' => 'Attached', 'Properties' => ['ConnectionType' => 'Network', 'SerialNumber' => '00008110-AAAA']],
            ['DeviceID' => 9, 'MessageType' => 'Attached', 'Properties' => ['ConnectionType' => 'USB', 'SerialNumber' => '00008110-BBBB', 'ProductID' => 4776]],
        ]]);
        $decoded = Plist::decode($xml);

        $this->assertSame('00008110-BBBB', $decoded['DeviceList'][1]['Properties']['SerialNumber']);
    }

    public function testAFrameIsAHeaderOfItsOwnLengthThenThePlist(): void
    {
        $frame = Usbmux::frame(['MessageType' => 'ListDevices'], 4);
        $header = unpack('Vlength/Vversion/Vmessage/Vtag', substr($frame, 0, 16));

        $this->assertSame(strlen($frame), $header['length']);
        $this->assertSame(1, $header['version']);
        $this->assertSame(8, $header['message'], 'plist');
        $this->assertSame(4, $header['tag']);
        $this->assertSame('ListDevices', Plist::decode(substr($frame, 16))['MessageType']);
        $this->assertSame('pig-iphone-use', Plist::decode(substr($frame, 16))['ClientVersionString']);
    }

    public function testAResponseIsSplitAndAChunkedBodyJoined(): void
    {
        [$status, $body] = Wda::parseResponse("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n{\"value\":1}");
        $this->assertSame(200, $status);
        $this->assertSame('{"value":1}', $body);

        [$status, $body] = Wda::parseResponse("HTTP/1.1 404 Not Found\r\nTransfer-Encoding: chunked\r\n\r\n5\r\n{\"val\r\n6\r\nue\":2}\r\n0\r\n\r\n");
        $this->assertSame(404, $status);
        $this->assertSame('{"value":2}', $body);
    }

    public function testNoAnswerSaysSo(): void
    {
        $this->expectExceptionMessage('closed the connection without answering');
        Wda::parseResponse('');
    }
}
