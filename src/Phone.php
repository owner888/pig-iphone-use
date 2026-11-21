<?php

declare(strict_types=1);

namespace PigIphoneUse;

use Pig\Async\Async;
use RuntimeException;

/**
 * One iPhone, as the tools use it. The model works in the screenshot's pixels — the coordinates
 * of what it sees, the tree's `rect` and `tap` included — and `pixel_to_point` turns them into the
 * points WDA taps at, here and nowhere else.
 */
final class Phone
{
    /** How long a cold WDA gets to answer after xcodebuild starts it. */
    private const float START_TIMEOUT = 120.0;

    public readonly Config $config;

    public readonly Wda $wda;

    public readonly Runner $runner;

    public readonly Apps $apps;

    /** @var array{points: array{width: float, height: float}, viewport: array{width: int, height: int}, pixel_to_point: array{float, float}}|null */
    private ?array $space = null;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();
        $this->wda = new Wda($this->config);
        $this->runner = new Runner($this->config);
        $this->apps = new Apps();
    }

    /**
     * WDA answering and a session open — starting WDA from its build when nothing answers and
     * $start allows it. What it found, for the model.
     *
     * @return array<string, mixed>
     */
    public function ready(bool $start = true): array
    {
        $status = $this->wda->status();
        $started = false;

        if ($status === null && $start && $this->config->wdaUrl() === null) {
            $device = $this->wda->device();
            [$build, $why] = $this->config->build();
            if ($build === null) {
                throw new RuntimeException("WDA is not running on the iPhone ({$device['udid']}), and pig cannot start it: {$why}. Set WDA up once as pig-iphone-use's README says (\"Setting up WDA\"), or with the iPhone-use plugin's setup, which pig reuses.");
            }

            $this->runner->start();
            $started = true;
            $deadline = microtime(true) + self::START_TIMEOUT;
            while ($status === null && microtime(true) < $deadline) {
                if ($this->runner->running() === null) {
                    break;
                }
                self::pause(1.0);
                $status = $this->wda->status(2.0);
            }
        }

        if ($status === null) {
            $failure = $started ? $this->runner->failure() : null;

            throw new RuntimeException($started
                ? 'WDA did not come up. ' . ($failure !== null ? "xcodebuild said:\n{$failure}\n" : '') . "Its log: {$this->runner->log()}. The phone must be unlocked, with Developer Mode on and this Mac's developer certificate trusted (Settings → General → VPN & Device Management)."
                : 'WDA is not answering on the iPhone. Start it with iphone_ready.');
        }

        $this->wda->reset();
        $this->space = null;
        $this->wda->session();
        $space = $this->space();
        $locked = $this->wda->locked();

        return [
            'ready' => !$locked,
            'locked' => $locked,
            'started_wda' => $started,
            'device' => $this->config->wdaUrl() ?? $this->wda->device()['udid'],
            'ios' => $status['os']['version'] ?? null,
            'app' => $this->wda->activeApp()['bundle_id'],
            'viewport' => $space['viewport'],
            'pixel_to_point' => $space['pixel_to_point'],
        ] + ($locked ? ['hint' => 'The iPhone is locked: ask the person to unlock it (pig does not enter passcodes), then call iphone_ready again.'] : []);
    }

    /**
     * The coordinates the model works in: the screen's pixels fitted as a screenshot is, and the
     * points one of those pixels is. Asked of WDA once, and again after `forget()`.
     *
     * @return array{points: array{width: float, height: float}, viewport: array{width: int, height: int}, pixel_to_point: array{float, float}}
     */
    public function space(): array
    {
        if ($this->space !== null) {
            return $this->space;
        }

        $points = $this->wda->windowSize();
        $scale = $this->wda->scale();
        $fitted = ScreenCapture::fitDimensions((int) round($points['width'] * $scale), (int) round($points['height'] * $scale));

        return $this->space = self::spaceOf($points, $fitted['width'], $fitted['height']);
    }

    /** After the screen turned: the next look asks WDA for its size again. */
    public function forget(): void
    {
        $this->space = null;
    }

    /**
     * @param array{width: float, height: float} $points
     * @return array{points: array{width: float, height: float}, viewport: array{width: int, height: int}, pixel_to_point: array{float, float}}
     */
    public static function spaceOf(array $points, int $width, int $height): array
    {
        return [
            'points' => $points,
            'viewport' => ['width' => $width, 'height' => $height],
            'pixel_to_point' => [round($points['width'] / $width, 4), round($points['height'] / $height, 4)],
        ];
    }

    /** @return array{float, float} a point in the model's coordinates as iPhone points */
    public function toPoints(float $x, float $y): array
    {
        $ratio = $this->space()['pixel_to_point'];

        return [$x * $ratio[0], $y * $ratio[1]];
    }

    /**
     * A screenshot, fitted and saved; its coordinates become the ones the model works in.
     *
     * @return array{path: string, mimeType: string, width: int, height: int, data: string}
     */
    public function screenshot(): array
    {
        $shot = ScreenCapture::process($this->wda->screenshot());
        $points = $this->wda->windowSize();
        // A turned phone: the capture is the other way round from the window WDA reports.
        if (($shot['raw_width'] > $shot['raw_height']) !== ($points['width'] > $points['height'])) {
            $points = ['width' => $points['height'], 'height' => $points['width']];
        }
        $this->space = self::spaceOf($points, $shot['width'], $shot['height']);

        return ['path' => $shot['path'], 'mimeType' => $shot['mimeType'], 'width' => $shot['width'], 'height' => $shot['height'], 'data' => $shot['data']];
    }

    /**
     * The view tree, as nodes in the model's coordinates.
     *
     * @return array<string, mixed>
     */
    public function tree(int $maxNodes): array
    {
        $space = $this->space();
        $parsed = TreeParser::parse($this->wda->source(), $space['pixel_to_point'], $space['points'], $maxNodes, $total);
        $result = [
            'app' => $parsed['app'] ?? $this->wda->activeApp()['bundle_id'],
            'viewport' => $space['viewport'],
            'total_nodes' => $total,
            'nodes' => $parsed['nodes'],
        ];

        if ($total > count($parsed['nodes'])) {
            $result['truncated'] = "{$total} useful nodes on screen, the first " . count($parsed['nodes']) . ' shown: call again with a larger max_nodes (up to 200) if a target is missing';
        }
        if ($total === 0) {
            $result['warning'] = 'Nothing named on this screen: it may be locked, loading, drawn by the app itself (a game, a map), or iPhone Mirroring may be holding it. Look with a screenshot before acting.';
        }

        return $result;
    }

    public function tap(float $x, float $y): void
    {
        [$px, $py] = $this->toPoints($x, $y);
        $this->wda->tap($px, $py);
    }

    /** @return array{from: array{float, float}, to: array{float, float}} the gesture, in the model's coordinates */
    public function swipe(?string $direction, ?array $from, ?array $to, int $durationMs): array
    {
        $viewport = $this->space()['viewport'];

        if ($direction !== null) {
            $w = $viewport['width'];
            $h = $viewport['height'];
            // Content moves with the finger: "up" scrolls down the page, as on android_swipe.
            [$from, $to] = match ($direction) {
                'up' => [[$w / 2, $h * 0.75], [$w / 2, $h * 0.30]],
                'down' => [[$w / 2, $h * 0.30], [$w / 2, $h * 0.75]],
                'left' => [[$w * 0.85, $h / 2], [$w * 0.15, $h / 2]],
                'right' => [[$w * 0.15, $h / 2], [$w * 0.85, $h / 2]],
                default => throw new RuntimeException('direction must be up, down, left or right.'),
            };
        }

        if ($from === null || $to === null) {
            throw new RuntimeException('Give a direction, or from_x, from_y, to_x and to_y.');
        }

        [$fx, $fy] = $this->toPoints((float) $from[0], (float) $from[1]);
        [$tx, $ty] = $this->toPoints((float) $to[0], (float) $to[1]);
        $this->wda->drag($fx, $fy, $tx, $ty, max(0.05, min(3.0, $durationMs / 1000)));

        return ['from' => [(float) $from[0], (float) $from[1]], 'to' => [(float) $to[0], (float) $to[1]]];
    }

    /**
     * Types into the focused field and reads it back.
     *
     * @return array<string, mixed>
     */
    public function type(string $text, bool $submit): array
    {
        $field = $this->wda->focused();
        if ($field === null) {
            throw new RuntimeException('No field has the keyboard focus: tap the field first (its `tap` from iphone_tree), then type.');
        }

        // Pieces of at most 40 characters, each a short request: WDA types at a fixed pace.
        foreach (self::pieces($text, 40) as $piece) {
            $this->wda->keys($piece);
        }

        $after = $this->wda->focused();
        $result = ['typed_length' => mb_strlen($text)];

        if ($field['secure'] || ($after !== null && $after['secure'])) {
            $result['verified'] = null;
            $result['note'] = 'A password field reads back as dots: not checked.';
        } else {
            $value = $after['value'] ?? null;
            $result['verified'] = is_string($value) && str_contains($value, $text);
            $result['text'] = $value;
            if ($result['verified'] === false) {
                $result['note'] = 'The field does not hold the text: an input method or the app may have changed it. Look before going on.';
            }
        }

        if ($submit) {
            $this->wda->keys("\n");
            $result['submitted'] = true;
        }

        return $result;
    }

    /**
     * $text cut into pieces of about $size characters, never inside a character.
     *
     * @return list<string>
     */
    public static function pieces(string $text, int $size): array
    {
        $characters = function_exists('grapheme_str_split') ? (grapheme_str_split($text) ?: mb_str_split($text)) : mb_str_split($text);

        return array_map(static fn (array $chunk): string => implode('', $chunk), array_chunk($characters, max(1, $size)));
    }

    public function launch(string $app): string
    {
        $device = $this->config->wdaUrl() === null ? $this->wda->device()['udid'] : $this->config->udid();
        $bundle = $this->apps->resolve($app, $device);
        $this->wda->activate($bundle);
        $this->forget();

        return $bundle;
    }

    private static function pause(float $seconds): void
    {
        \Fiber::getCurrent() !== null ? Async::delay($seconds) : usleep((int) ($seconds * 1e6));
    }
}
