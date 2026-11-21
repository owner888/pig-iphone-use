<?php

declare(strict_types=1);

namespace PigIphoneUse\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigIphoneUse\Config;
use PigIphoneUse\Usbmux;
use PigIphoneUse\Wda;

/** Through a stand-in usbmuxd with a stand-in WDA behind it: the whole way a request goes. */
final class UsbmuxTest extends TestCase
{
    private string $socket;

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        $this->socket = sys_get_temp_dir() . '/pig-usbmuxd-' . bin2hex(random_bytes(4)) . '.sock';
        $this->server = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/fake-usbmuxd.php', $this->socket, dirname(__DIR__, 2) . '/src/Plist.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertSame("ready\n", fgets($pipes[1]));
        putenv('USBMUXD_SOCKET_ADDRESS=unix:' . $this->socket);
    }

    protected function tearDown(): void
    {
        putenv('USBMUXD_SOCKET_ADDRESS');
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        @unlink($this->socket);
    }

    public function testThePhoneIsListedAndWdaIsReachedThroughIt(): void
    {
        $this->assertSame([['device_id' => 42, 'udid' => '00008110-FAKE', 'connection' => 'USB', 'product_id' => null]], Usbmux::devices());

        $wda = new Wda(new Config([]));
        $this->assertSame('18.1', $wda->status()['os']['version'] ?? null);
        $this->assertSame('S1', $wda->session());
        $this->assertSame(['width' => 393.0, 'height' => 852.0], $wda->windowSize());
    }

    public function testInsideACoroutineTheWaitsGoThroughTheLoop(): void
    {
        // How the tools run in pig: waiting on the phone suspends the call, the terminal goes on.
        $ticked = 0;
        \Pig\Async\Loop::reset();
        $size = \Pig\Async\Async::run(static function () use (&$ticked): array {
            $wda = new Wda(new Config([]));
            \Pig\Async\Async::spawn(static function () use (&$ticked): void {
                $ticked++;
            });

            return $wda->windowSize();
        });

        $this->assertSame(['width' => 393.0, 'height' => 852.0], $size);
        $this->assertSame(1, $ticked, 'other work ran while the request waited');
    }

    public function testAPortNothingListensOnSaysSo(): void
    {
        $this->expectExceptionMessage('nothing is listening on that port (is WDA running?)');
        Usbmux::connect(42, 9999);
    }
}
