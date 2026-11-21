<?php

declare(strict_types=1);

namespace PigIphoneUse\Test\Unit;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Extensions\ExtensionLoader;

final class ExtensionTest extends TestCase
{
    public function testTheExtensionLoadsWithItsToolsAndCommand(): void
    {
        $root = dirname(__DIR__, 2);
        [$loaded, $errors] = ExtensionLoader::load($root, cliPaths: [$root . '/extensions/pig-iphone-use.php']);
        $this->assertSame([], $errors);

        $extension = null;
        foreach ($loaded as $candidate) {
            if ($candidate->name === 'pig-iphone-use') {
                $extension = $candidate;
            }
        }
        $this->assertNotNull($extension);

        $tools = array_map(static fn ($tool) => $tool->name, $extension->api->tools());
        foreach (['iphone_ready', 'iphone_observe', 'iphone_tree', 'iphone_tap', 'iphone_swipe', 'iphone_type_text', 'iphone_press_button', 'iphone_launch_app', 'iphone_batch'] as $name) {
            $this->assertContains($name, $tools);
        }
        $this->assertArrayHasKey('iphone', $extension->api->commands());

        foreach ($extension->api->tools() as $tool) {
            $this->assertLessThanOrEqual(1500, strlen($tool->description), "{$tool->name}'s description fits pig's budget");
        }
    }
}
