<?php

declare(strict_types=1);

namespace PigIphoneUse;

use RuntimeException;

/**
 * WebDriverAgent on the phone, over HTTP: through a usbmuxd tunnel to its port on the phone, or
 * over TCP when `IPHONE_WDA_URL` says where it is (WDA over Wi-Fi, a simulator). One connection a
 * request (`Connection: close`), which is what WDA's server expects anyway.
 *
 * Coordinates are iPhone points throughout; converting from what the model sees is the caller's.
 */
final class Wda
{
    /** WDA's own default port on the phone. */
    public const int DEFAULT_PORT = 8100;

    private ?string $session = null;

    private ?float $scale = null;

    /** @var array{device_id: int, udid: string}|null the phone this client talks to, once chosen */
    private ?array $device = null;

    public function __construct(
        private readonly Config $config,
    ) {
    }

    /** The phone in use: the configured one if it is connected, else the first USB one. */
    public function device(): array
    {
        if ($this->device !== null) {
            return $this->device;
        }

        $devices = Usbmux::devices();
        if ($devices === []) {
            throw new RuntimeException('No iPhone is connected. Plug it in over USB, unlock it, and tap Trust if it asks.');
        }

        $wanted = $this->config->udid();
        foreach ($devices as $device) {
            if ($wanted === null || $device['udid'] === $wanted) {
                return $this->device = $device;
            }
        }

        throw new RuntimeException("The configured iPhone ({$wanted}) is not connected; connected: " . implode(', ', array_column($devices, 'udid')) . '.');
    }

    /** Forget the session and the phone: after WDA restarted, or a phone was plugged in. */
    public function reset(): void
    {
        $this->session = null;
        $this->scale = null;
        $this->device = null;
    }

    /** WDA's `/status`, or null when nothing answers. */
    public function status(float $timeout = 3.0): ?array
    {
        try {
            $reply = $this->request('GET', '/status', null, $timeout);
        } catch (RuntimeException) {
            return null;
        }

        return is_array($reply['value'] ?? null) ? $reply['value'] : null;
    }

    /**
     * One request; the decoded JSON reply. A WDA error (`value.error`) is thrown with its message.
     *
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $payload = null, float $timeout = 15.0): array
    {
        $body = $payload === null ? '' : (string) json_encode($payload === [] ? new \stdClass() : $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $head = "{$method} {$path} HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\nConnection: close\r\n";
        if ($payload !== null) {
            $head .= "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n";
        }

        $stream = $this->open(min($timeout, 5.0));
        try {
            Wire::write($stream, $head . "\r\n" . $body, $timeout);
            $raw = Wire::readAll($stream, $timeout);
        } finally {
            fclose($stream);
        }

        [$status, $text] = self::parseResponse($raw);
        $json = json_decode($text, true);
        if (!is_array($json)) {
            throw new RuntimeException("WDA answered {$method} {$path} with HTTP {$status} and no JSON.");
        }

        $value = $json['value'] ?? null;
        if (is_array($value) && isset($value['error'])) {
            throw new WdaError((string) $value['error'], (string) ($value['message'] ?? $value['error']));
        }
        if ($status >= 400) {
            throw new WdaError('http_' . $status, "WDA answered {$method} {$path} with HTTP {$status}.");
        }

        return $json;
    }

    /**
     * A request in the session, which is made (and set up) on first use and again when WDA says
     * it is gone. Returns `value`.
     *
     * @param array<string, mixed>|null $payload
     */
    public function call(string $method, string $path, ?array $payload = null, float $timeout = 15.0): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            $session = $this->session();

            try {
                return $this->request($method, "/session/{$session}{$path}", $payload, $timeout)['value'] ?? null;
            } catch (WdaError $error) {
                if ($attempt === 0 && $error->wdaCode === 'invalid session id') {
                    $this->session = null;
                    continue;
                }

                throw $error;
            }
        }
    }

    public function session(): string
    {
        if ($this->session !== null) {
            return $this->session;
        }

        $reply = $this->request('POST', '/session', ['capabilities' => ['alwaysMatch' => [
            'shouldWaitForQuiescence' => false,
            'shouldTerminateApp' => false,
            'waitForIdleTimeout' => 0,
            'maxTypingFrequency' => 30,
        ]]], 30.0);
        $id = $reply['sessionId'] ?? ($reply['value']['sessionId'] ?? null);
        if (!is_string($id) || $id === '') {
            throw new RuntimeException('WDA did not return a session.');
        }

        // No waiting for the app to be idle or for animations: a tap goes when it is asked for.
        $this->request('POST', "/session/{$id}/appium/settings", ['settings' => [
            'waitForIdleTimeout' => 0,
            'animationCoolOffTimeout' => 0,
        ]], 10.0);

        return $this->session = $id;
    }

    /** Points to pixels: 3 on most iPhones. */
    public function scale(): float
    {
        if ($this->scale === null) {
            $value = $this->request('GET', '/wda/screen', null, 5.0)['value'] ?? [];
            $this->scale = is_numeric($value['scale'] ?? null) && (float) $value['scale'] > 0 ? (float) $value['scale'] : 3.0;
        }

        return $this->scale;
    }

    /** @return array{width: float, height: float} the screen in points, as the app is turned */
    public function windowSize(): array
    {
        $value = $this->call('GET', '/window/size', null, 5.0);
        if (!is_array($value) || !is_numeric($value['width'] ?? null) || !is_numeric($value['height'] ?? null)) {
            throw new RuntimeException('WDA did not say how big the screen is.');
        }

        return ['width' => (float) $value['width'], 'height' => (float) $value['height']];
    }

    /** The accessibility tree of the foreground app, as WDA's XML. */
    public function source(): string
    {
        $xml = $this->call('GET', '/source?format=xml&excluded_attributes=visible', null, 30.0);
        if (!is_string($xml) || !str_contains($xml, '<')) {
            throw new RuntimeException('WDA did not return the view tree.');
        }

        return $xml;
    }

    /** A PNG of the screen, at the phone's own resolution. */
    public function screenshot(): string
    {
        $encoded = $this->request('GET', '/screenshot', null, 20.0)['value'] ?? null;
        $png = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($png === false || !str_starts_with($png, "\x89PNG")) {
            throw new RuntimeException('WDA did not return a PNG screenshot.');
        }

        return $png;
    }

    /** @return array{bundle_id: ?string, name: ?string, pid: ?int} */
    public function activeApp(): array
    {
        $value = $this->request('GET', '/wda/activeAppInfo', null, 5.0)['value'] ?? [];
        $bundle = is_string($value['bundleId'] ?? null) && !str_starts_with($value['bundleId'], 'local.pid.') ? $value['bundleId'] : null;

        return [
            'bundle_id' => $bundle,
            'name' => is_string($value['name'] ?? null) && $value['name'] !== '' ? $value['name'] : null,
            'pid' => is_int($value['pid'] ?? null) ? $value['pid'] : null,
        ];
    }

    public function locked(): bool
    {
        return ($this->request('GET', '/wda/locked', null, 5.0)['value'] ?? false) === true;
    }

    public function tap(float $x, float $y): void
    {
        $this->call('POST', '/wda/tap', ['x' => round($x, 1), 'y' => round($y, 1)]);
    }

    public function drag(float $fromX, float $fromY, float $toX, float $toY, float $seconds): void
    {
        $this->call('POST', '/wda/dragfromtoforduration', [
            'fromX' => round($fromX, 1), 'fromY' => round($fromY, 1),
            'toX' => round($toX, 1), 'toY' => round($toY, 1),
            'duration' => round($seconds, 2),
        ], 30.0);
    }

    /** Types into whatever has the keyboard focus, as the keyboard would. */
    public function keys(string $text, float $timeout = 30.0): void
    {
        $this->call('POST', '/wda/keys', ['value' => [$text], 'frequency' => 30], $timeout);
    }

    /**
     * The element that has the keyboard focus: its type and value, or null when none has.
     *
     * @return array{type: ?string, value: ?string, secure: bool}|null
     */
    public function focused(): ?array
    {
        try {
            $element = $this->call('GET', '/element/active', null, 5.0);
        } catch (WdaError) {
            return null;
        }

        $id = is_array($element) ? ($element['ELEMENT'] ?? $element['element-6066-11e4-a52e-4f735466cecf'] ?? null) : null;
        if (!is_string($id)) {
            return null;
        }

        $path = '/element/' . rawurlencode($id);
        $type = $this->call('GET', $path . '/attribute/type', null, 5.0);
        $value = $this->call('GET', $path . '/attribute/value', null, 5.0);

        return [
            'type' => is_string($type) ? $type : null,
            'value' => is_string($value) ? $value : null,
            'secure' => is_string($type) && str_contains($type, 'Secure'),
        ];
    }

    public function pressButton(string $name): void
    {
        $this->call('POST', '/wda/pressButton', ['name' => $name]);
    }

    /** The Home screen: WDA's own route, which brings SpringBoard forward rather than only pressing the button. */
    public function home(): void
    {
        $this->request('POST', '/wda/homescreen', [], 10.0);
    }

    public function activate(string $bundleId): void
    {
        $this->call('POST', '/wda/apps/activate', ['bundleId' => $bundleId], 45.0);
    }

    /**
     * Status line and body of a whole HTTP/1.1 response; chunked bodies are joined.
     *
     * @return array{int, string}
     */
    public static function parseResponse(string $raw): array
    {
        $split = strpos($raw, "\r\n\r\n");
        if ($split === false || preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $raw, $status) !== 1) {
            throw new RuntimeException($raw === '' ? 'WDA closed the connection without answering.' : 'WDA answered something that is not HTTP.');
        }

        $headers = strtolower(substr($raw, 0, $split));
        $body = substr($raw, $split + 4);

        if (preg_match('/^transfer-encoding:\s*chunked/m', $headers) === 1) {
            $joined = '';
            while (($line = strpos($body, "\r\n")) !== false) {
                $size = hexdec(trim(explode(';', substr($body, 0, $line))[0]));
                if ($size === 0) {
                    break;
                }
                $joined .= substr($body, $line + 2, (int) $size);
                $body = (string) substr($body, $line + 2 + (int) $size + 2);
            }
            $body = $joined;
        }

        return [(int) $status[1], $body];
    }

    /** @return resource */
    private function open(float $timeout)
    {
        $url = $this->config->wdaUrl();

        if ($url !== null) {
            $parts = parse_url($url);
            $host = $parts['host'] ?? '127.0.0.1';
            $port = $parts['port'] ?? self::DEFAULT_PORT;
            $stream = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
            if ($stream === false) {
                throw new RuntimeException("Cannot reach WDA at {$url}: {$errstr}.");
            }
            stream_set_blocking($stream, false);

            return $stream;
        }

        return Usbmux::connect($this->device()['device_id'], $this->config->devicePort(), $timeout);
    }
}
