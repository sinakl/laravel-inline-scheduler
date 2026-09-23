# Changelog

All notable changes to `laravel-inline-scheduler` will be documented in this file.

## 1.0.1 - 2026-09-23

- Fixed CI: pin `orchestra/testbench` to the matching version for each Laravel version in the test matrix.
- Disabled Composer's advisory-blocking resolution policy for dev dependencies, so CI can install older Laravel 11.x releases affected by (unrelated) historical security advisories.
- Removed the unnecessary `>> /dev/null 2>&1` shell redirection from the README's cron example and documented why it isn't needed.

## 1.0.0 - 2026-09-22

- Initial release.
