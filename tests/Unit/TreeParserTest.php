<?php

declare(strict_types=1);

namespace PigIphoneUse\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigIphoneUse\Phone;
use PigIphoneUse\TreeParser;

final class TreeParserTest extends TestCase
{
    private const string SOURCE = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <AppiumAUT>
          <XCUIElementTypeApplication type="XCUIElementTypeApplication" name="设置" label="设置" bundleId="com.apple.Preferences" x="0" y="0" width="393" height="852">
            <XCUIElementTypeWindow type="XCUIElementTypeWindow" x="0" y="0" width="393" height="852">
              <XCUIElementTypeStatusBar type="XCUIElementTypeStatusBar" x="0" y="0" width="393" height="54">
                <XCUIElementTypeStaticText type="XCUIElementTypeStaticText" label="9:41" x="30" y="15" width="40" height="20"/>
              </XCUIElementTypeStatusBar>
              <XCUIElementTypeSearchField type="XCUIElementTypeSearchField" name="搜索" label="搜索" value="搜索" x="16" y="140" width="361" height="36"/>
              <XCUIElementTypeCell type="XCUIElementTypeCell" name="com.apple.settings.general" label="通用" x="16" y="300" width="361" height="44">
                <XCUIElementTypeStaticText type="XCUIElementTypeStaticText" label="通用" x="60" y="310" width="40" height="24"/>
              </XCUIElementTypeCell>
              <XCUIElementTypeSwitch type="XCUIElementTypeSwitch" label="飞行模式" value="0" enabled="false" x="300" y="200" width="51" height="31"/>
              <XCUIElementTypeButton type="XCUIElementTypeButton" label="Below" x="16" y="900" width="100" height="44"/>
              <XCUIElementTypeKeyboard type="XCUIElementTypeKeyboard" x="0" y="600" width="393" height="252">
                <XCUIElementTypeKey type="XCUIElementTypeKey" label="q" x="3" y="610" width="35" height="42"/>
              </XCUIElementTypeKeyboard>
            </XCUIElementTypeWindow>
          </XCUIElementTypeApplication>
        </AppiumAUT>
        XML;

    public function testNodesAreInTheScreenshotsPixelsWithTheirTapPoint(): void
    {
        // An iPhone 15: 393×852 points, 1179×2556 pixels, a screenshot fitted to 723×1568.
        $space = Phone::spaceOf(['width' => 393.0, 'height' => 852.0], 723, 1568);
        $parsed = TreeParser::parse(self::SOURCE, $space['pixel_to_point'], $space['points'], 60, $total);

        $this->assertSame('com.apple.Preferences', $parsed['app']);
        $byText = array_column($parsed['nodes'], null, 'text');

        $general = $byText['通用'];
        $this->assertSame('Cell', $general['type']);
        $this->assertSame('com.apple.settings.general', $general['id']);
        $this->assertSame([29, 552, 664, 81], $general['rect'], 'points ÷ (393/723)');
        $this->assertSame([29 + 332, 552 + 40], $general['tap']);

        // Tapped where the tree says, the tool lands inside the cell in points.
        [$px, $py] = [$general['tap'][0] * $space['pixel_to_point'][0], $general['tap'][1] * $space['pixel_to_point'][1]];
        $this->assertTrue($px > 16 && $px < 377 && $py > 300 && $py < 344);

        $this->assertFalse($byText['飞行模式']['enabled']);
        $this->assertSame('0', $byText['飞行模式']['value']);
        $this->assertSame('SearchField', $byText['搜索']['type']);
    }

    public function testTheClockKeysOffScreenAndRepeatedLabelsAreLeftOut(): void
    {
        $space = Phone::spaceOf(['width' => 393.0, 'height' => 852.0], 723, 1568);
        $parsed = TreeParser::parse(self::SOURCE, $space['pixel_to_point'], $space['points'], 60, $total);
        $texts = array_column($parsed['nodes'], 'text');

        $this->assertNotContains('9:41', $texts, 'the status bar');
        $this->assertNotContains('q', $texts, 'keyboard keys: type text with the tool');
        $this->assertNotContains('Below', $texts, 'below the screen');
        $this->assertSame(1, count(array_keys($texts, '通用')), 'the cell, not also the text inside it');
        $this->assertSame(3, $total);
    }

    public function testPastTheLimitTheRestIsCounted(): void
    {
        $space = Phone::spaceOf(['width' => 393.0, 'height' => 852.0], 723, 1568);
        $parsed = TreeParser::parse(self::SOURCE, $space['pixel_to_point'], $space['points'], 1, $total);

        $this->assertCount(1, $parsed['nodes']);
        $this->assertSame(3, $total);
    }
}
