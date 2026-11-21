<?php

declare(strict_types=1);

namespace PigIphoneUse;

use Fiber;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use RuntimeException;

/**
 * Reading and writing a non-blocking stream without holding the event loop: inside a coroutine,
 * a wait suspends it on the loop (the terminal keeps drawing, keys keep arriving); outside one, it
 * falls back to `stream_select()`. pig's own `Socket` speaks TCP only, and usbmuxd is a Unix socket.
 */
final class Wire
{
    /** @param resource $stream */
    public static function write($stream, string $data, float $timeout): void
    {
        $deadline = microtime(true) + $timeout;

        while ($data !== '') {
            $written = @fwrite($stream, $data);

            if ($written === false) {
                throw new RuntimeException('The connection to the iPhone was closed while writing.');
            }

            $data = (string) substr($data, $written);

            if ($data !== '') {
                self::wait($stream, true, $deadline);
            }
        }
    }

    /**
     * Exactly $length bytes, or fewer when the other end closes first.
     *
     * @param resource $stream
     */
    public static function readExactly($stream, int $length, float $timeout): string
    {
        $deadline = microtime(true) + $timeout;
        $out = '';

        while (strlen($out) < $length) {
            $chunk = @fread($stream, $length - strlen($out));

            if ($chunk === false || ($chunk === '' && feof($stream))) {
                break;
            }

            if ($chunk === '') {
                self::wait($stream, false, $deadline);
                continue;
            }

            $out .= $chunk;
        }

        return $out;
    }

    /**
     * Everything until the other end closes.
     *
     * @param resource $stream
     */
    public static function readAll($stream, float $timeout): string
    {
        $deadline = microtime(true) + $timeout;
        $out = '';

        while (true) {
            $chunk = @fread($stream, 65536);

            if ($chunk === false || ($chunk === '' && feof($stream))) {
                return $out;
            }

            if ($chunk === '') {
                self::wait($stream, false, $deadline);
                continue;
            }

            $out .= $chunk;
        }
    }

    /** @param resource $stream */
    private static function wait($stream, bool $forWriting, float $deadline): void
    {
        $left = $deadline - microtime(true);

        if ($left <= 0) {
            throw new RuntimeException('The iPhone did not answer in time.');
        }

        if (Fiber::getCurrent() === null) {
            $read = $forWriting ? [] : [$stream];
            $write = $forWriting ? [$stream] : [];
            $except = [];
            $seconds = (int) $left;
            if (@stream_select($read, $write, $except, $seconds, (int) (($left - $seconds) * 1e6)) === 0) {
                throw new RuntimeException('The iPhone did not answer in time.');
            }

            return;
        }

        $loop = Loop::get();
        $deferred = new Deferred();
        $timer = null;
        $watcher = null;
        $settle = static function (?RuntimeException $error) use ($loop, $deferred, &$timer, &$watcher): void {
            if ($deferred->isComplete()) {
                return;
            }
            $loop->cancel((string) $watcher);
            $loop->cancel((string) $timer);
            $error === null ? $deferred->complete() : $deferred->error($error);
        };

        $watcher = $forWriting
            ? $loop->onWritable($stream, static fn () => $settle(null))
            : $loop->onReadable($stream, static fn () => $settle(null));
        $timer = $loop->delay($left, static fn () => $settle(new RuntimeException('The iPhone did not answer in time.')));

        $deferred->future->await();
    }
}
