# Changelog

All notable changes to `laravel-inline-scheduler` will be documented in this file.

## 1.0.2 - 2026-09-28

- Fixed: events using `runInBackground()` never called `finish()`, so their `after()`/`onSuccess()`/`onFailure()` callbacks never fired, `exitCode` was never set, and — combined with `withoutOverlapping()` — their mutex was never released, silently skipping the event on every subsequent run until the mutex expired (up to 24 hours by default). `InlineEvent` now always finishes synchronously, since it never actually detaches a background process.
- Documented that `->user('someuser')` is silently ignored by `InlineEvent`, since switching OS user needs a real subprocess.

## 1.0.1 - 2026-09-23

- Fixed CI: pin `orchestra/testbench` to the matching version for each Laravel version in the test matrix.
- Disabled Composer's advisory-blocking resolution policy for dev dependencies, so CI can install older Laravel 11.x releases affected by (unrelated) historical security advisories.
- Removed the unnecessary `>> /dev/null 2>&1` shell redirection from the README's cron example and documented why it isn't needed.

## 1.0.0 - 2026-09-22

- Initial release.
