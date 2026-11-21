# pig-iphone-use

pig 编程助手的 iPhone 操作：纯 PHP 通过 WebDriverAgent 经 USB 操作真机 iPhone——控件树、点击、滑动、中文输入、截图、打开 App。不需要 Python，也不需要 Node.js：pig 直接走 macOS 自带的 usbmuxd 连手机。

[English](README.md)

## 安装

```bash
pig install git:github.com/owner888/pig-iphone-use
```

需要 macOS 和 Xcode、一台经 USB 连接的 iPhone（已信任、开启开发者模式），以及为它构建并签名过一次的 WebDriverAgent（WDA），见下文。准备好之后，`iphone_ready` 会在 WDA 没运行时自己把它启动起来。

## 工具

工具与 [pig-android-use](https://github.com/owner888/pig-android-use) 对齐：坐标是截图里的像素，控件树的每个节点都带着该点的 `tap` 坐标。

| 工具 | 作用 |
| --- | --- |
| `iphone_ready` | 检查手机和 WDA，没有应答时用已有构建启动 WDA；并说明手机是否锁屏 |
| `iphone_observe` | 截图、控件树，或两者 |
| `iphone_tree` | 控件树：每个有名字的控件的类型、文字、范围和 `tap` |
| `iphone_tap` | 点节点的 `tap`，或截图里的某个点 |
| `iphone_swipe` | 按方向或在两点之间滑动 |
| `iphone_type_text` | 往获得焦点的输入框里打字（中文、表情都行），再读回来核对：`verified` |
| `iphone_press_button` | home、volumeup、volumedown |
| `iphone_launch_app` | 按常用名称或 bundle id 打开 App：苹果自带应用、手机上已安装的应用、经过核验的常用应用目录，绝不猜 |
| `iphone_batch` | 一次执行多步，遇到第一个错误就停 |

`/iphone status | start | stop | screenshot` 查看手机、WDA，以及 pig 启动的 xcodebuild。

`iphone-use` 技能告诉模型怎么用这些工具，以及什么时候停下来把手机交还给人（锁屏密码、面容 ID、付款）。

## 配置 WDA

每台手机做一次。如果这台 Mac 上用 [iPhone Use](https://github.com/zhongerxin/iPhone-use) 插件配置过 WDA，pig 会在 `~/.local/share/iphone-use` 找到它的配置并复用那份构建，可跳过本节。

1. iPhone 上：连接电脑，点**信任**，打开**开发者模式**（设置 → 隐私与安全性）。
2. 准备你的 Apple 开发团队 ID（10 个字符：Xcode → 设置 → Accounts，或 developer.apple.com → Membership）和手机的 UDID（`xcrun devicectl list devices`）。
3. 获取 WDA，用你的团队签名、用你自己的 bundle id 构建：
   ```bash
   mkdir -p ~/.pig/agent/iphone-use && cd ~/.pig/agent/iphone-use
   git clone --depth 1 https://github.com/appium/WebDriverAgent.git
   xcodebuild -project WebDriverAgent/WebDriverAgent.xcodeproj -scheme WebDriverAgentRunner \
     -destination id=<UDID> -derivedDataPath derived_data -allowProvisioningUpdates \
     DEVELOPMENT_TEAM=<TEAM_ID> CODE_SIGN_STYLE=Automatic PRODUCT_BUNDLE_IDENTIFIER=<com.you.WebDriverAgentRunner> \
     build-for-testing
   ```
4. iPhone 上信任你的开发者证书：设置 → 通用 → VPN 与设备管理。
5. 在 `~/.pig/agent/iphone-use/config.json` 里告诉 pig：
   ```json
   {
     "udid": "<UDID>",
     "team_id": "<TEAM_ID>",
     "bundle_id": "<com.you.WebDriverAgentRunner>",
     "source_dir": "~/.pig/agent/iphone-use/WebDriverAgent",
     "device_port": 8100
   }
   ```

然后让 pig 在手机上做点事：`iphone_ready` 会启动 WDA（`xcodebuild … test-without-building`，日志在 `~/.pig/agent/iphone-use/wda.log`）。启动时手机需要保持解锁。

## 配置项

| 变量 | 含义 |
| --- | --- |
| `IPHONE_UDID` | 连了多台 iPhone 时，用哪一台 |
| `IPHONE_WDA_PORT` | WDA 在手机上的端口（默认 8100） |
| `IPHONE_WDA_URL` | 不走 USB、经网络连接 WDA，例如 `http://192.168.1.20:8100`，或模拟器的 `http://127.0.0.1:8100` |
| `IPHONE_USE_STATE_DIR` | iPhone Use 插件的配置目录（默认 `~/.local/share/iphone-use`） |

## 开发

测试依赖一份 pig 源码；其中一个测试会起一个假的 usbmuxd，后面挂一个假的 WDA：

```bash
PIG_DIR=~/path/to/pig ~/path/to/pig/vendor/bin/phpunit -c phpunit.xml
```

## 许可证

MIT，见 [LICENSE](LICENSE) 和 [NOTICE](NOTICE)。
