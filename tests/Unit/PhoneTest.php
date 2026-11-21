<?php

declare(strict_types=1);

namespace PigIphoneUse\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigIphoneUse\Apps;
use PigIphoneUse\Config;
use PigIphoneUse\Phone;
use PigIphoneUse\Runner;

final class PhoneTest extends TestCase
{
    public function testTextIsTypedInPiecesThatNeverSplitACharacter(): void
    {
        $text = str_repeat('显示', 30) . '👍🏽';
        $pieces = Phone::pieces($text, 40);

        $this->assertSame($text, implode('', $pieces));
        $this->assertCount(2, $pieces);
        $this->assertSame(['hi'], Phone::pieces('hi', 40));
    }

    public function testAppsAreFoundByNameAndNeverGuessed(): void
    {
        $apps = new Apps();

        $this->assertSame('com.tencent.xin', $apps->resolve('com.tencent.xin', null), 'a bundle id as given');
        $this->assertSame('com.apple.Preferences', $apps->resolve('设置', null));
        $this->assertSame('com.apple.Preferences', $apps->resolve('settings', null));
        $this->assertSame('com.tencent.xin', $apps->resolve('微信', null), 'the catalog');
        $this->assertSame('com.tencent.xin', $apps->resolve('WeChat', null), 'and its aliases');

        $this->expectExceptionMessage('No app called "没有这个"');
        $apps->resolve('没有这个', null);
    }

    public function testDevicectlsListOfAppsIsRead(): void
    {
        $json = json_encode(['result' => ['apps' => [
            ['bundleIdentifier' => 'com.example.one', 'name' => 'One'],
            ['bundleIdentifier' => 'com.example.two'],
        ]]]);

        $this->assertSame([['name' => 'One', 'bundle_id' => 'com.example.one']], Apps::parseInstalled((string) $json));
    }

    public function testABuildIsFoundFromTheConfigTheIphoneUsePluginWrote(): void
    {
        $dir = sys_get_temp_dir() . '/pig-iphone-config-' . bin2hex(random_bytes(4));
        mkdir("{$dir}/state/derived_data", 0o700, true);
        mkdir("{$dir}/wda/WebDriverAgent.xcodeproj", 0o700, true);
        file_put_contents("{$dir}/state/config.json", json_encode([
            'udid' => '00008110-AAAA', 'team_id' => 'ABCDE12345', 'bundle_id' => 'com.example.WebDriverAgentRunner',
            'device_port' => 8100, 'local_port' => 18100, 'source_dir' => "{$dir}/wda",
        ]));

        try {
            $config = new Config(["{$dir}/missing.json", "{$dir}/state/config.json"]);
            [$build, $why] = $config->build();

            $this->assertNull($why);
            $this->assertSame("{$dir}/state/derived_data", $build['derived_data']);
            $this->assertSame(8100, $build['port']);

            $command = Runner::command($build);
            $this->assertSame('test-without-building', $command[count($command) - 1]);
            $this->assertContains('id=00008110-AAAA', $command);
            $this->assertContains('DEVELOPMENT_TEAM=ABCDE12345', $command);
            $this->assertContains('USE_PORT=8100', $command);

            [$none, $why] = (new Config(["{$dir}/missing.json"]))->build();
            $this->assertNull($none);
            $this->assertSame('no WDA has been set up on this Mac yet', $why);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
