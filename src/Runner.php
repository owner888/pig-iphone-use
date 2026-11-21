<?php

declare(strict_types=1);

namespace PigIphoneUse;

use RuntimeException;

/**
 * Starting WDA on the phone from a build already made: `xcodebuild test-without-building` runs
 * WebDriverAgentRunner's test, which is WDA's server, and keeps it running for as long as the
 * process lives. pig starts it detached, so it outlives the call (and pig), writes its output to
 * `wda.log` and its pid to `wda.pid` under `~/.pig/agent/iphone-use/`.
 *
 * Building and signing it the first time is not here: that is the setup flow, which needs the
 * person's Apple team.
 */
final class Runner
{
    public function __construct(private readonly Config $config)
    {
    }

    public function log(): string
    {
        return $this->config->own() . '/wda.log';
    }

    /** The pid of the xcodebuild pig started, while it runs. */
    public function running(): ?int
    {
        $file = $this->config->own() . '/wda.pid';
        $pid = is_file($file) ? (int) trim((string) file_get_contents($file)) : 0;

        return $pid > 0 && self::alive($pid) ? $pid : null;
    }

    /**
     * The command that starts the build in $build.
     *
     * @param array{udid: string, team_id: string, bundle_id: string, port: int, project: string, derived_data: string} $build
     * @return list<string>
     */
    public static function command(array $build): array
    {
        return [
            'xcodebuild',
            '-project', $build['project'],
            '-scheme', 'WebDriverAgentRunner',
            '-configuration', 'Debug',
            '-destination', 'id=' . $build['udid'],
            '-derivedDataPath', $build['derived_data'],
            '-allowProvisioningUpdates',
            'DEVELOPMENT_TEAM=' . $build['team_id'],
            'CODE_SIGN_STYLE=Automatic',
            'CODE_SIGN_IDENTITY=Apple Development',
            'PRODUCT_BUNDLE_IDENTIFIER=' . $build['bundle_id'],
            'USE_PORT=' . $build['port'],
            'test-without-building',
        ];
    }

    /** Start it; the pid. Waiting for WDA to answer is the caller's. */
    public function start(): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            throw new RuntimeException('Starting WDA needs macOS and Xcode.');
        }

        $running = $this->running();
        if ($running !== null) {
            return $running;
        }

        [$build, $why] = $this->config->build();
        if ($build === null) {
            throw new RuntimeException("Cannot start WDA: {$why}. Set it up once as pig-iphone-use's README says (\"Setting up WDA\").");
        }

        $dir = $this->config->own();
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        $line = implode(' ', array_map('escapeshellarg', self::command($build)));
        $pid = (int) trim((string) shell_exec('nohup ' . $line . ' > ' . escapeshellarg($this->log()) . ' 2>&1 < /dev/null & echo $!'));
        if ($pid <= 0) {
            throw new RuntimeException('Could not start xcodebuild.');
        }

        file_put_contents($dir . '/wda.pid', (string) $pid);

        return $pid;
    }

    public function stop(): bool
    {
        $pid = $this->running();
        if ($pid === null) {
            return false;
        }

        // xcodebuild ends the test, and with it WDA, on SIGTERM.
        shell_exec('kill ' . $pid . ' 2>/dev/null');
        @unlink($this->config->own() . '/wda.pid');

        return true;
    }

    /** The last lines of the log that say why a start failed, if it says. */
    public function failure(): ?string
    {
        $log = is_file($this->log()) ? (string) file_get_contents($this->log()) : '';
        $lines = array_values(array_filter(explode("\n", $log), static fn (string $line): bool => preg_match('/error|failed|unable|not been|untrusted|locked|developer mode/i', $line) === 1));

        return $lines === [] ? null : implode("\n", array_slice($lines, -5));
    }

    private static function alive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        exec('kill -0 ' . $pid . ' 2>/dev/null', $output, $exit);

        return $exit === 0;
    }
}
