---
name: iphone-use
description: Drive an iPhone over USB through WebDriverAgent — taps, swipes, Chinese text, the view tree, screenshots, opening apps by name (微信, Safari…). Start with iphone_ready; the first WDA setup is the person's, per the README.
---

# Doing a task on an iPhone

The `iphone_*` tools drive an iPhone connected to this Mac over USB, through WebDriverAgent (WDA), Appium's on-device test runner. They work like `android_*`: the same coordinates (the screenshot's pixels, and each tree node's `tap`), the same way of observing.

Settle what the person wants delivered and when it is done. Treat an action that returned normally as done and move on; do not spend a separate call proving each tap worked. When the next step needs to see the screen, set `observe` on the action itself.

## 1. Ready

Call `iphone_ready()` once at the start of a task.

- `ready: true`: carry on. It gives the foreground app (`app`, a bundle id), the `viewport` and `pixel_to_point`.
- It starts WDA itself when nothing answers, from the build made at setup — up to two minutes the first time. If it says there is no build, or xcodebuild failed, tell the person what it said: setting WDA up (signing it with their Apple team) is theirs to do once, as the package's README describes.
- `locked: true`: ask the person to unlock the phone, then call `iphone_ready` again. Never enter a passcode.
- A later call that fails with WDA unreachable or an invalid session: `iphone_ready` again, once, then repeat the step that failed only if it did not happen.

## 2. Looking

- `iphone_tree()` first: every named control with its `type`, `text`, `rect` [x, y, width, height] and `tap`. Exact and cheap.
- `iphone_observe()` for a screenshot, `mode: "both"` for both. Use the screenshot when the tree is empty or says little — games, maps, video, pages drawn by the app itself — or to check what something looks like.
- Past `max_nodes` the tree says `truncated`; ask for more (up to 200) when a target is missing.

## 3. Tapping

**Coordinates come from a node's `tap`, or from where the thing is in the screenshot — never from arithmetic on the screen size.** Tab bars are not evenly spaced and a guess can open the wrong thing. If the target is in neither, look again.

- `iphone_tap(x, y)` with the numbers as you see them; the tool turns them into iPhone points.
- A tap that changes the page: add `observe: "tree"` (or `"screenshot"`) to see the new page in the same call.

## 4. Typing

- Tap the field, then `iphone_type_text(text: "…")`. Chinese, emoji and several lines work; it types through WDA, not a keyboard app.
- The result reads the field back: `verified: false` means look before going on — an input method can change text, and a field can limit it. A password field is not read back (`verified: null`).
- `submit: true` presses Return afterwards (search, send). Do not submit what the person has not asked to send.

## 5. Swiping, buttons, apps

- `iphone_swipe(direction: "up")` scrolls the page down, as a finger moving up does. `from_x`… `to_y` for a precise drag.
- **There is no back button**: tap the screen's own back control (top left, often `<` or the previous page's title), or swipe right from the left edge: `iphone_swipe(from_x: 2, from_y: <middle>, to_x: <two thirds across>, to_y: <middle>)`.
- `iphone_press_button(name: "home")` goes to the Home screen.
- `iphone_launch_app(app: "微信")`: by the name the person uses or by bundle id. Names are matched against Apple's apps, the apps installed on the phone, and a checked catalog; when it cannot tell, it lists the candidates — pick by bundle id, never guess one.
- `iphone_batch` runs known steps in one call (tap, swipe, type_text, press_button, launch_app, wait); it stops at the first error and says where.

## 6. What you learn about an app

Keep notes on the apps you work in: `~/.pig/agent/iphone-use/notes/<bundle id>.md`. Read them before working in the app; after the task, add what this run showed (what you did and then saw, positions in words, never pixels; dated) and correct what turned out wrong.

## 7. When to stop and hand over

Stop and ask the person when the phone wants a passcode, Face ID or Touch ID, an Apple ID password, a payment or a purchase confirmation, a permission prompt you were not told to accept, or a CAPTCHA. Say what is on the screen and what you need them to do; carry on after they say it is done. iPhone Mirroring on the Mac takes the screen away from WDA: if the tree is empty while the phone is on, ask the person to close it.
