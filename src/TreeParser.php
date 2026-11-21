<?php

declare(strict_types=1);

namespace PigIphoneUse;

use RuntimeException;
use SimpleXMLElement;

/**
 * WDA's page source (XCUIElementType… elements, rects in points) as the compact nodes the model
 * reads, in the screenshot's coordinates — the same shape as pig-android-use's, so one way of
 * working serves both phones: `type`, `text`, `rect` [x, y, width, height], `tap` (its centre),
 * and `id` / `value` / `enabled` where they add something.
 */
final class TreeParser
{
    /** Types worth listing even without text: what a person taps or types into. */
    private const array ACTIONABLE = [
        'Button', 'Cell', 'Link', 'Switch', 'Toggle', 'Slider', 'TextField', 'SecureTextField', 'SearchField',
        'TextView', 'Tab', 'SegmentedControl', 'PickerWheel', 'Stepper', 'Image', 'Icon', 'MenuItem',
    ];

    /** Never listed: the clock and battery, and keyboard keys (type text with the tool instead). */
    private const array SKIPPED = ['StatusBar', 'Key', 'Keyboard'];

    /**
     * @param array{float, float} $pointsPerPixel the screenshot's pixel_to_point: points per pixel of what the model sees
     * @param array{width: float, height: float} $screen the screen in points
     * @param-out int $total useful nodes on the screen, listed or not
     * @return array{app: ?string, nodes: list<array<string, mixed>>}
     */
    public static function parse(string $xml, array $pointsPerPixel, array $screen, int $maxNodes = 60, ?int &$total = null): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_PARSEHUGE);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($root === false) {
            throw new RuntimeException('The view tree from WDA is not XML.');
        }

        $app = null;
        $first = $root->getName() === 'AppiumAUT' ? ($root->children()[0] ?? null) : $root;
        if ($first !== null && (string) ($first['type'] ?? '') === 'XCUIElementTypeApplication') {
            $bundle = (string) ($first['bundleId'] ?? '');
            $app = $bundle !== '' && !str_starts_with($bundle, 'local.pid.') ? $bundle : null;
        }

        $nodes = [];
        $total = 0;
        self::walk($root, $pointsPerPixel, $screen, $maxNodes, $nodes, $total, null);

        return ['app' => $app, 'nodes' => $nodes];
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param array{float, float} $ratio
     * @param array{width: float, height: float} $screen
     */
    private static function walk(SimpleXMLElement $element, array $ratio, array $screen, int $max, array &$nodes, int &$total, ?string $parentText): void
    {
        $type = preg_replace('/^XCUIElementType/', '', (string) ($element['type'] ?? $element->getName())) ?? '';

        if (in_array($type, self::SKIPPED, true)) {
            return;
        }

        $label = trim((string) ($element['label'] ?? ''));
        $name = trim((string) ($element['name'] ?? ''));
        $value = trim((string) ($element['value'] ?? ''));
        $text = $label !== '' ? $label : ($value !== '' ? $value : $name);
        $kept = null;

        $x = (float) ($element['x'] ?? 0);
        $y = (float) ($element['y'] ?? 0);
        $width = (float) ($element['width'] ?? 0);
        $height = (float) ($element['height'] ?? 0);
        $onScreen = $width > 0 && $height > 0 && $x < $screen['width'] && $y < $screen['height'] && $x + $width > 0 && $y + $height > 0;
        // The app and its window span the screen and are named after the app: never a target.
        $useful = !in_array($type, ['Application', 'Window'], true) && ($text !== '' || in_array($type, self::ACTIONABLE, true));
        // A label that repeats its parent's (the text inside a cell named after it) adds nothing.
        $useful = $useful && !($type === 'StaticText' && $text === $parentText);

        if ($onScreen && $useful) {
            $total++;

            if (count($nodes) < $max) {
                // Clipped to the screen, then into the screenshot's pixels.
                $left = max(0.0, $x);
                $top = max(0.0, $y);
                $right = min($screen['width'], $x + $width);
                $bottom = min($screen['height'], $y + $height);
                $rect = [
                    (int) round($left / $ratio[0]),
                    (int) round($top / $ratio[1]),
                    (int) round(($right - $left) / $ratio[0]),
                    (int) round(($bottom - $top) / $ratio[1]),
                ];

                $node = ['type' => $type, 'tap' => [$rect[0] + intdiv($rect[2], 2), $rect[1] + intdiv($rect[3], 2)], 'rect' => $rect];
                if ($text !== '') {
                    $node['text'] = $text;
                }
                if ($name !== '' && $name !== $text) {
                    $node['id'] = $name;
                }
                if ($value !== '' && $value !== $text) {
                    $node['value'] = $value;
                }
                if ((string) ($element['enabled'] ?? 'true') === 'false') {
                    $node['enabled'] = false;
                }
                $nodes[] = $node;
            }

            $kept = $text !== '' ? $text : null;
        }

        foreach ($element->children() as $child) {
            self::walk($child, $ratio, $screen, $max, $nodes, $total, $kept ?? $parentText);
        }
    }
}
