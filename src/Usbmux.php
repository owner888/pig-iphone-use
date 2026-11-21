<?php

declare(strict_types=1);

namespace PigIphoneUse;

use RuntimeException;

/**
 * usbmuxd, the macOS daemon every USB-connected iPhone goes through: it lists the phones, and
 * turns a connection to it into a connection to a port on one of them. That is how WDA on the
 * phone is reached — no forwarding process, no Node.js: each request opens its own tunnel.
 *
 * The wire: a 16-byte little-endian header (length including the header, version 1, message 8 =
 * plist, a tag), then an XML property list. After a `Connect` that answers `Number` 0, the same
 * socket carries the phone's port byte for byte.
 */
final class Usbmux
{
    public const string SOCKET = '/var/run/usbmuxd';

    private const int VERSION = 1;
    private const int PLIST = 8;

    /** usbmuxd's result codes, in words. */
    private const array RESULTS = [
        1 => 'the request was malformed',
        2 => 'the phone is not connected',
        3 => 'the phone refused the connection: nothing is listening on that port (is WDA running?)',
        6 => 'usbmuxd does not support this',
    ];

    public static function available(): bool
    {
        return file_exists(self::path());
    }

    /**
     * The phones usbmuxd knows, USB first.
     *
     * @return list<array{device_id: int, udid: string, connection: string, product_id: int|null}>
     */
    public static function devices(float $timeout = 3.0): array
    {
        $stream = self::open($timeout);

        try {
            $reply = self::exchange($stream, ['MessageType' => 'ListDevices'], $timeout);
        } finally {
            fclose($stream);
        }

        $devices = [];
        foreach (is_array($reply['DeviceList'] ?? null) ? $reply['DeviceList'] : [] as $entry) {
            $properties = is_array($entry['Properties'] ?? null) ? $entry['Properties'] : [];
            $id = $entry['DeviceID'] ?? $properties['DeviceID'] ?? null;
            $udid = $properties['SerialNumber'] ?? null;

            if (!is_int($id) || !is_string($udid) || $udid === '') {
                continue;
            }

            $devices[] = [
                'device_id' => $id,
                'udid' => $udid,
                'connection' => (string) ($properties['ConnectionType'] ?? 'USB'),
                'product_id' => is_int($properties['ProductID'] ?? null) ? $properties['ProductID'] : null,
            ];
        }

        usort($devices, static fn (array $a, array $b): int => ($a['connection'] === 'USB' ? 0 : 1) <=> ($b['connection'] === 'USB' ? 0 : 1));

        return $devices;
    }

    /**
     * A stream connected to $port on the phone.
     *
     * @return resource
     */
    public static function connect(int $deviceId, int $port, float $timeout = 5.0)
    {
        $stream = self::open($timeout);

        try {
            $reply = self::exchange($stream, [
                'MessageType' => 'Connect',
                'DeviceID' => $deviceId,
                // The port in network byte order, read as a little-endian integer: usbmuxd's convention.
                'PortNumber' => (($port & 0xFF) << 8) | (($port >> 8) & 0xFF),
            ], $timeout);
        } catch (\Throwable $error) {
            fclose($stream);

            throw $error;
        }

        $result = $reply['Number'] ?? null;
        if ($result !== 0) {
            fclose($stream);
            $why = is_int($result) ? (self::RESULTS[$result] ?? "usbmuxd answered {$result}") : 'usbmuxd gave no answer';

            throw new RuntimeException("Cannot reach port {$port} on the iPhone: {$why}.");
        }

        return $stream;
    }

    /** The header and plist of one request, as written to usbmuxd. */
    public static function frame(array $message, int $tag = 1): string
    {
        $body = Plist::encode($message + ['ClientVersionString' => 'pig-iphone-use', 'ProgName' => 'pig']);

        return pack('VVVV', 16 + strlen($body), self::VERSION, self::PLIST, $tag) . $body;
    }

    private static function path(): string
    {
        $configured = getenv('USBMUXD_SOCKET_ADDRESS');

        return is_string($configured) && $configured !== '' ? preg_replace('#^unix:#', '', $configured) ?? self::SOCKET : self::SOCKET;
    }

    /** @return resource */
    private static function open(float $timeout)
    {
        $path = self::path();
        $stream = @stream_socket_client('unix://' . $path, $errno, $errstr, $timeout);

        if ($stream === false) {
            throw new RuntimeException(PHP_OS_FAMILY === 'Darwin'
                ? "Cannot open usbmuxd ({$path}): {$errstr}. Is the iPhone connected over USB, and is this Mac trusted on it?"
                : 'iPhone use needs macOS: it reaches the phone through usbmuxd, which comes with macOS.');
        }

        stream_set_blocking($stream, false);

        return $stream;
    }

    /**
     * @param resource $stream
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private static function exchange($stream, array $message, float $timeout): array
    {
        Wire::write($stream, self::frame($message), $timeout);
        $header = Wire::readExactly($stream, 16, $timeout);

        if (strlen($header) < 16) {
            throw new RuntimeException('usbmuxd closed the connection without answering.');
        }

        $length = unpack('Vlength', $header)['length'] ?? 0;
        $body = Wire::readExactly($stream, max(0, $length - 16), $timeout);
        $reply = Plist::decode($body);

        if (!is_array($reply)) {
            throw new RuntimeException('usbmuxd answered something that is not a dictionary.');
        }

        return $reply;
    }
}
