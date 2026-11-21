# Changelog

What changed, newest first, in pig's format: `## [x.y.z] - YYYY-MM-DD`, then only the sections that
have entries (`### New Features`, `### Added`, `### Changed`, `### Fixed`). Notes under
`## Unreleased` belong to no version yet; the version itself lives in the git tag.

## Unreleased

### New Features

- iPhone use in pure PHP: `iphone_ready`, `iphone_observe`, `iphone_tree`, `iphone_tap`, `iphone_swipe`, `iphone_type_text`, `iphone_press_button`, `iphone_launch_app`, `iphone_batch` and `/iphone`, matching pig-android-use's tools. WebDriverAgent is reached through macOS's usbmuxd, a tunnel per request, with no Python or Node.js; the waits suspend the call on pig's loop rather than holding the terminal. `iphone_ready` starts WDA from an existing signed build (`xcodebuild test-without-building`), its own or the one the iPhone Use plugin made. Typing reads the field back (`verified`); apps are opened by name from Apple's apps, the phone's installed apps (`devicectl`) and a checked catalog.
