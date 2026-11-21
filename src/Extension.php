<?php

declare(strict_types=1);

namespace PigIphoneUse;

use Closure;
use Pig\Agent\AgentToolResult;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\CodingAgent\Extensions\ToolDefinition;
use Pig\CodingAgent\Extensions\ExtensionApi;
use RuntimeException;

/**
 * iPhone use for pig: drive a real iPhone over USB through WebDriverAgent, in pure PHP. The tools
 * match pig-android-use's — same names after the prefix, same coordinates (the screenshot's
 * pixels, with each tree node's `tap`), same observations — so one way of working serves both.
 */
final class Extension
{
    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function register(ExtensionApi $pig): void
    {
        ScreenCapture::useDirectory($pig->artifactsDirectory(...));
        $phone = new Phone();

        $observeParam = [
            'type' => 'string',
            'enum' => ['none', 'screenshot', 'tree'],
            'description' => 'What to look at after the action: none (default), a screenshot, or the view tree.',
        ];

        /**
         * A result: the facts as JSON, then what was asked to be looked at.
         *
         * @param array<string, mixed> $facts
         */
        $result = static function (array $facts, string $observe = 'none', int $maxNodes = 60) use ($phone): AgentToolResult {
            $image = null;
            if ($observe === 'tree') {
                $facts['observation'] = $phone->tree($maxNodes);
            } elseif ($observe === 'screenshot') {
                $image = $phone->screenshot();
                $facts['observation'] = [
                    'app' => $phone->wda->activeApp()['bundle_id'],
                    'viewport' => ['width' => $image['width'], 'height' => $image['height']],
                    'pixel_to_point' => $phone->space()['pixel_to_point'],
                    'image_path' => $image['path'],
                ];
            }

            $contents = [new TextContent(self::json($facts))];
            if ($image !== null) {
                $contents[] = new ImageContent(base64_encode($image['data']), $image['mimeType']);
            }

            return new AgentToolResult($contents);
        };

        $tool = static function (string $name, string $label, string $description, array $properties, array $required, Closure $run) use ($pig): void {
            $pig->registerTool(new ToolDefinition(
                name: $name,
                label: $label,
                description: $description,
                parameters: ['type' => 'object', 'properties' => $properties, 'required' => $required],
                execute: static fn (string $id, array $params): AgentToolResult => $run($params),
            ));
        };

        $tool(
            'iphone_ready',
            'iPhone Ready',
            'Check the iPhone is connected and WebDriverAgent (WDA) answers, starting WDA from its existing build if it is not running (up to two minutes the first time). Call first in a conversation, and again after an error that says WDA is unreachable. ready=false with locked=true: ask the person to unlock the phone.',
            ['screenshot' => ['type' => 'boolean', 'description' => 'Also return a screenshot. Default false.']],
            [],
            static fn (array $params) => $result($phone->ready(), ($params['screenshot'] ?? false) === true ? 'screenshot' : 'none'),
        );

        $tool(
            'iphone_observe',
            'iPhone Observe',
            'Look at the iPhone: a screenshot (default), the view tree, or both. Coordinates in the result are the screenshot\'s pixels; tap a tree node at its `tap`.',
            [
                'mode' => ['type' => 'string', 'enum' => ['screenshot', 'tree', 'both'], 'description' => 'Default screenshot.'],
                'max_nodes' => ['type' => 'integer', 'description' => 'Tree nodes to list, default 60, at most 200.'],
            ],
            [],
            static function (array $params) use ($phone, $result): AgentToolResult {
                $mode = $params['mode'] ?? 'screenshot';
                $max = max(1, min(200, (int) ($params['max_nodes'] ?? 60)));
                if ($mode !== 'both') {
                    return $result([], $mode === 'tree' ? 'tree' : 'screenshot', $max);
                }

                $shown = $result([], 'screenshot');
                $tree = $phone->tree($max);
                $facts = json_decode($shown->content[0]->text, true) + ['tree' => $tree];

                return new AgentToolResult([new TextContent(self::json($facts)), ...array_slice($shown->content, 1)]);
            },
        );

        $tool(
            'iphone_tree',
            'iPhone Tree',
            'The view tree of the screen: each node\'s type, text, rect [x, y, width, height] and `tap` (its centre), in the screenshot\'s pixels. Tap a node at its `tap`. Faster than a screenshot and exact; when it is empty or says little (a game, a map), look with a screenshot.',
            ['max_nodes' => ['type' => 'integer', 'description' => 'Nodes to list, default 60, at most 200.']],
            [],
            static fn (array $params) => $result([], 'tree', max(1, min(200, (int) ($params['max_nodes'] ?? 60)))),
        );

        $tool(
            'iphone_tap',
            'iPhone Tap',
            'Tap at x, y — a tree node\'s `tap`, or a point read off the latest screenshot. Never compute coordinates from the screen size.',
            [
                'x' => ['type' => 'number'],
                'y' => ['type' => 'number'],
                'observe' => $observeParam,
            ],
            ['x', 'y'],
            static function (array $params) use ($phone, $result): AgentToolResult {
                $phone->tap((float) $params['x'], (float) $params['y']);

                return $result(['action_executed' => true, 'tapped' => [(float) $params['x'], (float) $params['y']]], $params['observe'] ?? 'none');
            },
        );

        $tool(
            'iphone_swipe',
            'iPhone Swipe',
            'Swipe: a direction (up scrolls the page down, as a finger moving up does), or from_x, from_y, to_x, to_y in the screenshot\'s pixels.',
            [
                'direction' => ['type' => 'string', 'enum' => ['up', 'down', 'left', 'right']],
                'from_x' => ['type' => 'number'], 'from_y' => ['type' => 'number'],
                'to_x' => ['type' => 'number'], 'to_y' => ['type' => 'number'],
                'duration_ms' => ['type' => 'integer', 'description' => 'Default 300.'],
                'observe' => $observeParam,
            ],
            [],
            static function (array $params) use ($phone, $result): AgentToolResult {
                $from = isset($params['from_x'], $params['from_y']) ? [$params['from_x'], $params['from_y']] : null;
                $to = isset($params['to_x'], $params['to_y']) ? [$params['to_x'], $params['to_y']] : null;
                $gesture = $phone->swipe($params['direction'] ?? null, $from, $to, (int) ($params['duration_ms'] ?? 300));

                return $result(['action_executed' => true, 'gesture' => $gesture], $params['observe'] ?? 'none');
            },
        );

        $tool(
            'iphone_type_text',
            'iPhone Type Text',
            'Type into the field that has the keyboard focus (tap it first) — Chinese, emoji and several lines too — then read the field back: `verified` false means look before going on. submit=true presses Return after it.',
            [
                'text' => ['type' => 'string'],
                'submit' => ['type' => 'boolean', 'description' => 'Press Return afterwards. Default false.'],
                'observe' => $observeParam,
            ],
            ['text'],
            static fn (array $params) => $result(['action_executed' => true] + $phone->type((string) $params['text'], ($params['submit'] ?? false) === true), $params['observe'] ?? 'none'),
        );

        $tool(
            'iphone_press_button',
            'iPhone Press Button',
            'Press home, volumeup or volumedown. There is no back button on an iPhone: use the screen\'s own back control (top left) or swipe right from the left edge.',
            [
                'name' => ['type' => 'string', 'enum' => ['home', 'volumeup', 'volumedown']],
                'observe' => $observeParam,
            ],
            ['name'],
            static function (array $params) use ($phone, $result): AgentToolResult {
                $name = (string) $params['name'];
                $name === 'home' ? $phone->wda->home() : $phone->wda->pressButton($name);
                $phone->forget();

                return $result(['action_executed' => true, 'button' => $name], $params['observe'] ?? 'none');
            },
        );

        $tool(
            'iphone_launch_app',
            'iPhone Launch App',
            'Open an app, or bring it forward: its name as the person says it (微信, Settings, 招商银行) or its bundle id. Names are looked up among Apple\'s apps, the apps installed on the phone, and a catalog of common ones — never guessed.',
            [
                'app' => ['type' => 'string', 'description' => 'Name or bundle id.'],
                'observe' => $observeParam,
            ],
            ['app'],
            static fn (array $params) => $result(['action_executed' => true, 'bundle_id' => $phone->launch((string) $params['app'])], $params['observe'] ?? 'none'),
        );

        $tool(
            'iphone_batch',
            'iPhone Batch',
            'Several known steps in one call — tap, swipe, type_text, press_button, launch_app, wait — each {"op": …, "args": {…}} with the args of its tool. Stops at the first error and says which step; observe applies after the last step.',
            [
                'steps' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'object', 'properties' => [
                    'op' => ['type' => 'string', 'enum' => ['tap', 'swipe', 'type_text', 'press_button', 'launch_app', 'wait']],
                    'args' => ['type' => 'object'],
                ], 'required' => ['op']]],
                'observe' => $observeParam,
            ],
            ['steps'],
            static function (array $params) use ($phone, $result): AgentToolResult {
                $done = [];
                foreach ((array) $params['steps'] as $index => $step) {
                    $args = is_array($step['args'] ?? null) ? $step['args'] : [];
                    try {
                        $done[] = ['op' => $step['op'] ?? null] + self::step($phone, (string) ($step['op'] ?? ''), $args);
                    } catch (RuntimeException $error) {
                        return $result(['completed' => count($done), 'stopped_at' => $index, 'error' => $error->getMessage(), 'results' => $done], $params['observe'] ?? 'none');
                    }
                }

                return $result(['completed' => count($done), 'results' => $done], $params['observe'] ?? 'none');
            },
        );

        $pig->registerCommand(
            name: 'iphone',
            description: 'iPhone: /iphone status | start | stop | screenshot',
            handler: static function (string $args, $ctx) use ($phone): void {
                $sub = strtolower(trim($args)) ?: 'status';

                try {
                    $ctx->ui->notify(match ($sub) {
                        'status' => self::status($phone),
                        'start' => self::json($phone->ready()),
                        'stop' => $phone->runner->stop() ? 'Stopped the WDA pig started.' : 'pig has not started a WDA that is still running.',
                        'screenshot' => 'Saved: ' . $phone->screenshot()['path'],
                        default => "Unknown: /iphone {$sub}. Try /iphone status | start | stop | screenshot.",
                    });
                } catch (RuntimeException $error) {
                    $ctx->ui->notify($error->getMessage(), 'error');
                }
            },
        );
    }

    /**
     * One batch step.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private static function step(Phone $phone, string $op, array $args): array
    {
        switch ($op) {
            case 'tap':
                $phone->tap((float) ($args['x'] ?? throw new RuntimeException('tap needs x and y')), (float) ($args['y'] ?? throw new RuntimeException('tap needs x and y')));

                return ['ok' => true];
            case 'swipe':
                $from = isset($args['from_x'], $args['from_y']) ? [$args['from_x'], $args['from_y']] : null;
                $to = isset($args['to_x'], $args['to_y']) ? [$args['to_x'], $args['to_y']] : null;

                return ['ok' => true, 'gesture' => $phone->swipe($args['direction'] ?? null, $from, $to, (int) ($args['duration_ms'] ?? 300))];
            case 'type_text':
                $typed = $phone->type((string) ($args['text'] ?? ''), ($args['submit'] ?? false) === true);
                if ($typed['verified'] === false) {
                    throw new RuntimeException('The text did not arrive in the field: ' . ($typed['note'] ?? ''));
                }

                return ['ok' => true] + $typed;
            case 'press_button':
                $name = (string) ($args['name'] ?? 'home');
                $name === 'home' ? $phone->wda->home() : $phone->wda->pressButton($name);
                $phone->forget();

                return ['ok' => true, 'button' => $name];
            case 'launch_app':
                return ['ok' => true, 'bundle_id' => $phone->launch((string) ($args['app'] ?? throw new RuntimeException('launch_app needs app')))];
            case 'wait':
                $seconds = max(0.1, min(10.0, (float) ($args['seconds'] ?? 1.0)));
                \Fiber::getCurrent() !== null ? \Pig\Async\Async::delay($seconds) : usleep((int) ($seconds * 1e6));

                return ['ok' => true, 'seconds' => $seconds];
            default:
                throw new RuntimeException("Unknown step '{$op}'.");
        }
    }

    private static function status(Phone $phone): string
    {
        $lines = [];
        $lines[] = 'Config: ' . ($phone->config->file() ?? 'none (see "Setting up WDA" in pig-iphone-use\'s README)');

        if ($phone->config->wdaUrl() === null) {
            if (!Usbmux::available()) {
                return implode("\n", [...$lines, 'usbmuxd: not found — iPhone use needs macOS.']);
            }
            $devices = Usbmux::devices();
            $lines[] = 'iPhones: ' . ($devices === [] ? 'none connected' : implode(', ', array_map(static fn (array $d): string => "{$d['udid']} ({$d['connection']})", $devices)));
        } else {
            $lines[] = 'WDA: ' . $phone->config->wdaUrl();
        }

        $status = $phone->wda->status();
        $lines[] = 'WDA: ' . ($status === null ? 'not answering' : 'answering, iOS ' . ($status['os']['version'] ?? '?'));
        $pid = $phone->runner->running();
        $lines[] = 'xcodebuild started by pig: ' . ($pid === null ? 'none' : "pid {$pid}, log {$phone->runner->log()}");

        return implode("\n", $lines);
    }
}
