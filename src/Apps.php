<?php

declare(strict_types=1);

namespace PigIphoneUse;

use RuntimeException;

/**
 * An app's bundle id from what the person calls it: a bundle id as given; Apple's own apps by
 * name; then the apps installed on the phone (`devicectl`, kept five minutes); then a catalog of
 * common apps whose ids were checked against the App Store (`resources/apps.json`). Never a guess
 * spelled from a brand name.
 */
final class Apps
{
    /** Apple's apps, under the names people use for them. */
    private const array SYSTEM = [
        'com.apple.Preferences' => ['设置', 'Settings'],
        'com.apple.mobilesafari' => ['Safari', 'Safari 浏览器', '浏览器'],
        'com.apple.MobileSMS' => ['信息', 'Messages', '短信'],
        'com.apple.mobilephone' => ['电话', 'Phone'],
        'com.apple.camera' => ['相机', 'Camera'],
        'com.apple.mobileslideshow' => ['照片', 'Photos', '相册'],
        'com.apple.AppStore' => ['App Store', '应用商店'],
        'com.apple.mobilemail' => ['邮件', 'Mail'],
        'com.apple.mobilecal' => ['日历', 'Calendar'],
        'com.apple.mobilenotes' => ['备忘录', 'Notes'],
        'com.apple.reminders' => ['提醒事项', 'Reminders'],
        'com.apple.Maps' => ['地图', 'Maps'],
        'com.apple.weather' => ['天气', 'Weather'],
        'com.apple.MobileAddressBook' => ['通讯录', 'Contacts'],
        'com.apple.mobiletimer' => ['时钟', 'Clock'],
        'com.apple.calculator' => ['计算器', 'Calculator'],
        'com.apple.Music' => ['音乐', 'Music'],
        'com.apple.DocumentsApp' => ['文件', 'Files'],
        'com.apple.Health' => ['健康', 'Health'],
    ];

    /** @var array{at: float, udid: string, apps: list<array{name: string, bundle_id: string}>}|null */
    private ?array $installed = null;

    public function resolve(string $query, ?string $udid): string
    {
        $query = trim($query);
        if (preg_match('/^[A-Za-z][\w-]*(\.[\w-]+)+$/', $query) === 1) {
            return $query;
        }

        $wanted = mb_strtolower($query);
        foreach (self::SYSTEM as $bundle => $names) {
            foreach ($names as $name) {
                if (mb_strtolower($name) === $wanted) {
                    return $bundle;
                }
            }
        }

        $installed = $udid !== null ? $this->installed($udid) : [];
        foreach ($installed as $app) {
            if (mb_strtolower($app['name']) === $wanted) {
                return $app['bundle_id'];
            }
        }

        foreach (self::catalog() as $app) {
            foreach ([$app['name'], ...$app['aliases']] as $name) {
                if (mb_strtolower($name) === $wanted) {
                    return $app['bundle_id'];
                }
            }
        }

        $near = array_values(array_filter($installed, static fn (array $app): bool => str_contains(mb_strtolower($app['name']), $wanted)));
        if (count($near) === 1) {
            return $near[0]['bundle_id'];
        }

        $names = array_map(static fn (array $app): string => "{$app['name']} ({$app['bundle_id']})", array_slice($near, 0, 8));

        throw new RuntimeException($near === []
            ? "No app called \"{$query}\" on the phone or in the catalog. Pass its bundle id, or ask what it is called."
            : "\"{$query}\" could be: " . implode(', ', $names) . '. Pass the bundle id.');
    }

    /** @return list<array{name: string, bundle_id: string}> */
    public function installed(string $udid): array
    {
        if ($this->installed !== null && $this->installed['udid'] === $udid && microtime(true) - $this->installed['at'] < 300) {
            return $this->installed['apps'];
        }

        if (PHP_OS_FAMILY !== 'Darwin') {
            return [];
        }

        $file = tempnam(sys_get_temp_dir(), 'pig-iphone-apps-');
        if ($file === false) {
            return [];
        }

        try {
            // --include-all-apps: without it devicectl lists only the apps a developer installed.
            exec(implode(' ', array_map('escapeshellarg', ['xcrun', 'devicectl', 'device', 'info', 'apps', '--device', $udid, '--include-all-apps', '--json-output', $file, '--timeout', '15'])) . ' >/dev/null 2>&1', $output, $exit);
            $apps = $exit === 0 ? self::parseInstalled((string) file_get_contents($file)) : [];
        } finally {
            @unlink($file);
        }

        $this->installed = ['at' => microtime(true), 'udid' => $udid, 'apps' => $apps];

        return $apps;
    }

    /** @return list<array{name: string, bundle_id: string}> */
    public static function parseInstalled(string $json): array
    {
        $document = json_decode($json, true);
        $apps = $document['result']['apps'] ?? $document['result']['installedApps'] ?? [];
        $rows = [];

        foreach (is_array($apps) ? $apps : [] as $key => $app) {
            if (!is_array($app)) {
                continue;
            }
            $bundle = $app['bundleIdentifier'] ?? $app['bundleId'] ?? (is_string($key) ? $key : null);
            $name = $app['name'] ?? $app['displayName'] ?? null;
            if (is_string($bundle) && $bundle !== '' && is_string($name) && $name !== '') {
                $rows[] = ['name' => $name, 'bundle_id' => $bundle];
            }
        }

        return $rows;
    }

    /** @return list<array{name: string, aliases: list<string>, bundle_id: string}> */
    public static function catalog(): array
    {
        static $catalog = null;
        if ($catalog !== null) {
            return $catalog;
        }

        $document = json_decode((string) @file_get_contents(dirname(__DIR__) . '/resources/apps.json'), true);
        $catalog = [];
        foreach (is_array($document['apps'] ?? null) ? $document['apps'] : [] as $app) {
            if (is_array($app) && is_string($app['name'] ?? null) && is_string($app['bundleId'] ?? null)) {
                $catalog[] = [
                    'name' => $app['name'],
                    'aliases' => array_values(array_filter((array) ($app['aliases'] ?? []), 'is_string')),
                    'bundle_id' => $app['bundleId'],
                ];
            }
        }

        return $catalog;
    }
}
