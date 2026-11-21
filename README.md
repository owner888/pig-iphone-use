# pig-iphone-use

iPhone use for the pig coding agent: drive a real iPhone over USB through WebDriverAgent, in pure PHP — the view tree, taps, swipes, Chinese text input, screenshots and apps. No Python, no Node.js: pig talks to the phone through macOS's own usbmuxd.

[中文](README.zh-CN.md)

## Installation

```bash
pig install git:github.com/owner888/pig-iphone-use
```

You need macOS with Xcode, an iPhone connected over USB (trusted, Developer Mode on), and WebDriverAgent (WDA) built and signed for it once — see below. With that done, `iphone_ready` starts WDA itself whenever it is not running.

## Tools

The tools match [pig-android-use](https://github.com/owner888/pig-android-use)'s: coordinates are the screenshot's pixels, and every tree node carries the `tap` point to use.

| Tool | What it does |
| --- | --- |
| `iphone_ready` | Checks the phone and WDA, starting WDA from its build when nothing answers; says whether the phone is locked |
| `iphone_observe` | A screenshot, the tree, or both |
| `iphone_tree` | The view tree: type, text, rect and `tap` of each named control |
| `iphone_tap` | Tap at a node's `tap` or a point in the screenshot |
| `iphone_swipe` | Swipe by direction or between two points |
| `iphone_type_text` | Type into the focused field (Chinese and emoji too), then read the field back: `verified` |
| `iphone_press_button` | home, volumeup, volumedown |
| `iphone_launch_app` | Open an app by the name people use or its bundle id — Apple's apps, the apps installed on the phone, a checked catalog; never a guess |
| `iphone_batch` | Several steps in one call, stopping at the first error |

`/iphone status | start | stop | screenshot` shows the phones, WDA and the xcodebuild pig started.

The `iphone-use` skill tells the model how to work with them, and when to stop and hand the phone back (passcodes, Face ID, payments).

## Setting up WDA

Once per phone. If the [iPhone Use](https://github.com/zhongerxin/iPhone-use) plugin set WDA up on this Mac, pig finds its configuration in `~/.local/share/iphone-use` and uses that build: skip this.

1. On the iPhone: connect it, tap **Trust**, and turn on **Developer Mode** (Settings → Privacy & Security).
2. Your Apple development team ID (10 characters: Xcode → Settings → Accounts, or developer.apple.com → Membership) and the phone's UDID (`xcrun devicectl list devices`).
3. Get WDA and build it, signed with your team, under a bundle id of your own:
   ```bash
   mkdir -p ~/.pig/agent/iphone-use && cd ~/.pig/agent/iphone-use
   git clone --depth 1 https://github.com/appium/WebDriverAgent.git
   xcodebuild -project WebDriverAgent/WebDriverAgent.xcodeproj -scheme WebDriverAgentRunner \
     -destination id=<UDID> -derivedDataPath derived_data -allowProvisioningUpdates \
     DEVELOPMENT_TEAM=<TEAM_ID> CODE_SIGN_STYLE=Automatic PRODUCT_BUNDLE_IDENTIFIER=<com.you.WebDriverAgentRunner> \
     build-for-testing
   ```
4. On the iPhone, trust your developer certificate: Settings → General → VPN & Device Management.
5. Tell pig where it is, in `~/.pig/agent/iphone-use/config.json`:
   ```json
   {
     "udid": "<UDID>",
     "team_id": "<TEAM_ID>",
     "bundle_id": "<com.you.WebDriverAgentRunner>",
     "source_dir": "~/.pig/agent/iphone-use/WebDriverAgent",
     "device_port": 8100
   }
   ```

Then ask pig to do something on the phone: `iphone_ready` starts WDA (`xcodebuild … test-without-building`, logged to `~/.pig/agent/iphone-use/wda.log`). The phone must be unlocked while it starts.

## Configuration

| Variable | Meaning |
| --- | --- |
| `IPHONE_UDID` | Which iPhone, when more than one is connected |
| `IPHONE_WDA_PORT` | WDA's port on the phone (default 8100) |
| `IPHONE_WDA_URL` | Reach WDA over the network instead of USB, e.g. `http://192.168.1.20:8100` or a simulator's `http://127.0.0.1:8100` |
| `IPHONE_USE_STATE_DIR` | Where the iPhone Use plugin keeps its configuration, if not `~/.local/share/iphone-use` |

## Development

The tests run against a pig checkout; one of them runs a stand-in usbmuxd with a stand-in WDA behind it:

```bash
PIG_DIR=~/path/to/pig ~/path/to/pig/vendor/bin/phpunit -c phpunit.xml
```

## License

MIT — see [LICENSE](LICENSE) and [NOTICE](NOTICE).
