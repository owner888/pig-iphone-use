<?php

declare(strict_types=1);

namespace PigIphoneUse;

use Pig\CodingAgent\Config as PigConfig;

/**
 * Which phone, which port, which WDA build: `~/.pig/agent/iphone-use/config.json` first, then a
 * configuration the iPhone-use plugin left (`~/.local/share/iphone-use`, or `IPHONE_USE_STATE_DIR`),
 * so a WDA it already signed and built is used as it is. Environment variables win over both:
 * `IPHONE_UDID`, `IPHONE_WDA_PORT`, and `IPHONE_WDA_URL` for a WDA reached over the network.
 *
 * The keys are the plugin's: `udid`, `team_id`, `bundle_id`, `device_port`, `source_dir`, with
 * the build under `derived_data` beside the file.
 */
final class Config
{
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    private ?string $file = null;

    /** @param list<string>|null $candidates config files to look in, in order; null for the usual ones */
    public function __construct(private readonly ?array $candidates = null)
    {
    }

    public function own(): string
    {
        return PigConfig::home() . '/iphone-use';
    }

    /** @return list<string> */
    public function candidates(): array
    {
        if ($this->candidates !== null) {
            return $this->candidates;
        }

        $home = rtrim((string) (getenv('HOME') ?: ''), '/');
        $state = getenv('IPHONE_USE_STATE_DIR') ?: getenv('WDA_STATE_DIR');

        return array_values(array_filter([
            $this->own() . '/config.json',
            is_string($state) && $state !== '' ? rtrim($state, '/') . '/config.json' : null,
            "{$home}/.local/share/iphone-use/config.json",
            "{$home}/.local/share/iphone-use-wda/config.json",
        ]));
    }

    /** The file the values came from, or null when there is none. */
    public function file(): ?string
    {
        $this->values();

        return $this->file;
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        foreach ($this->candidates() as $file) {
            $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (is_array($json)) {
                $this->file = $file;

                return $this->values = $json;
            }
        }

        return $this->values = [];
    }

    public function udid(): ?string
    {
        $value = getenv('IPHONE_UDID') ?: ($this->values()['udid'] ?? null);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function devicePort(): int
    {
        $value = getenv('IPHONE_WDA_PORT') ?: ($this->values()['device_port'] ?? Wda::DEFAULT_PORT);

        return is_numeric($value) && (int) $value > 0 && (int) $value < 65536 ? (int) $value : Wda::DEFAULT_PORT;
    }

    public function wdaUrl(): ?string
    {
        $value = getenv('IPHONE_WDA_URL');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * What `xcodebuild test-without-building` needs to start the WDA already built, or null with
     * the reason it cannot.
     *
     * @return array{array{udid: string, team_id: string, bundle_id: string, port: int, project: string, derived_data: string}|null, ?string}
     */
    public function build(): array
    {
        $values = $this->values();
        if ($this->file === null) {
            return [null, 'no WDA has been set up on this Mac yet'];
        }

        $udid = $this->udid();
        $team = $values['team_id'] ?? null;
        $bundle = $values['bundle_id'] ?? null;
        $source = $values['source_dir'] ?? null;
        if ($udid === null || !is_string($team) || !is_string($bundle) || !is_string($source)) {
            return [null, "{$this->file} lacks the phone, the team, the bundle id or the WDA source"];
        }

        $source = str_starts_with($source, '~/') ? rtrim((string) getenv('HOME'), '/') . substr($source, 1) : $source;
        $project = rtrim($source, '/') . '/WebDriverAgent.xcodeproj';
        $derived = dirname($this->file) . '/derived_data';
        if (!is_dir($project)) {
            return [null, "the WDA source is not at {$source}"];
        }
        if (!is_dir($derived)) {
            return [null, "WDA has not been built (no {$derived})"];
        }

        return [[
            'udid' => $udid,
            'team_id' => $team,
            'bundle_id' => $bundle,
            'port' => $this->devicePort(),
            'project' => $project,
            'derived_data' => $derived,
        ], null];
    }
}
