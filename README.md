# Laravel Inline Scheduler

Run Laravel's task scheduler on hosts where `proc_open` is disabled — most commonly shared hosting with a cPanel cron job — **without changing a single line of your existing `schedule()` definitions.**

## The problem

On many shared hosts, `proc_open` (and `proc_close`) are disabled for security reasons. Laravel's scheduler relies on it: every time you write

```php
$schedule->command('inventory:sync')->daily();
```

`schedule:run` spawns that command as a **separate subprocess** via Symfony's `Process`, which needs `proc_open`. Without it, you get:

```
The Process class relies on proc_open, which is not available on your PHP installation
```

and none of your `->command(...)` scheduled tasks run — only `->call(...)` closures do, since those already run in-process.

The usual workarounds are to ask your host to enable `proc_open` (often refused), rewrite every scheduled task from `->command()` to `->call(fn () => Artisan::call(...))` by hand, or write a one-off script that manually re-implements command dispatch. All three either aren't in your control or require maintaining a parallel, hand-written version of your schedule.

## What this package does

It swaps Laravel's `Schedule`/`Event` classes for two subclasses:

- `InlineSchedule` — identical to Laravel's `Schedule`, except `command()`/`exec()` build an `InlineEvent` instead of the stock `Event`.
- `InlineEvent` — identical to Laravel's `Event`, except the one protected method that shells out to `Process::fromShellCommandline(...)` is overridden to run the command in-process via `Artisan::call()` instead.

Everything else — `withoutOverlapping()`, `before()`/`after()`/`onSuccess()`/`onFailure()`, `sendOutputTo()`, environment/maintenance checks, `onOneServer()` — is inherited unchanged from Laravel's own classes, so it keeps working exactly as documented.

The package's service provider replaces the framework's `Schedule::class` container binding with one that builds an `InlineSchedule` and feeds it through your app's own `Kernel::schedule()` method (or, on Laravel 11+ apps using `routes/console.php`, lets the `Schedule` facade populate it the normal way). Either way, your scheduling code is untouched.

**Net effect:** `composer require` this package, and your existing cron entry —

```
* * * * * php /home/youruser/your-project/artisan schedule:run >> /dev/null 2>&1
```

— just starts working, even with `proc_open` disabled. `schedule:list` and `schedule:test` work too, since they go through the same `Schedule::class` binding.

## Installation

```bash
composer require vendor/laravel-inline-scheduler
```

> The `vendor/` part of the package name is a placeholder — replace it with a real Packagist namespace (e.g. your GitHub username) before publishing.

Nothing else to configure. The service provider is auto-discovered.

## Caveats

- **Shared process, not isolated processes.** Every due command now runs sequentially inside the *same* PHP process instead of its own subprocess. A command that leaks state (a stuck DB transaction, a mutated singleton, `config()` changes) could in principle affect the next command in the same run. This is rare in practice, but it's the one real tradeoff for working around `proc_open`.
- **`runInBackground()` runs synchronously instead.** True backgrounding needs a detached subprocess, which is exactly what we're avoiding. Backgrounded events still run, just not concurrently with the rest of the schedule.
- **Non-Artisan `exec()` calls still need `proc_open`.** If you call `$schedule->exec('some-other-binary --flag')` directly (not `->command()`), there's no Artisan command to run in-process, so it falls back to the normal subprocess path — the same as Laravel's default behavior today, not a regression.
- **Requires Laravel 11 or 12.** The package overrides a protected method (`Event::execute()`) that was introduced in this form for Laravel 11+. Earlier versions structure that method differently and aren't supported yet.

## Requirements

- PHP 8.2+
- Laravel 11.x or 12.x

## Testing

```bash
composer install
./vendor/bin/phpunit
```

The test suite boots a real Laravel app via Orchestra Testbench and verifies, against the actual framework classes, that: the `Schedule::class` binding resolves to `InlineSchedule`; `->command()` produces an `InlineEvent`; running that event executes the target command's `handle()` inside the *same* PHP process (a real subprocess couldn't mutate this process's static state, which is what the test checks); exit codes, `onFailure()`, `sendOutputTo()`, `withoutOverlapping()`, and a legacy `Kernel::schedule()` method all behave exactly as they do with stock Laravel.

## License

MIT
