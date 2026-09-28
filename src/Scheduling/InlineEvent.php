<?php

namespace LaravelInlineScheduler\Scheduling;

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * An Event that runs its Artisan command in the current PHP process instead
 * of spawning a subprocess via Symfony Process, which requires proc_open.
 *
 * Every other part of Event's lifecycle (mutex/withoutOverlapping, before/after
 * callbacks, exit-code based onSuccess/onFailure, output redirection) is left
 * untouched and inherited as-is from the parent class.
 */
class InlineEvent extends Event
{
    /**
     * Run the given event.
     *
     * Event::run() only calls finish() for foreground events, because a
     * backgrounded event normally finishes itself later by shelling out to
     * `artisan schedule:finish` at the end of its detached process. We never
     * detach anything — execute() below always runs synchronously — so we
     * always call finish() ourselves. Otherwise a runInBackground() event
     * would never fire its after()/onSuccess()/onFailure() callbacks, never
     * get its exitCode set, and — combined with withoutOverlapping() — never
     * release its mutex.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     *
     * @throws \Throwable
     */
    public function run(Container $container)
    {
        if ($this->shouldSkipDueToOverlapping()) {
            return;
        }

        $exitCode = $this->start($container);

        $this->finish($container, $exitCode);
    }

    /**
     * Run the command in-process via Artisan::call() instead of shelling out.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return int
     */
    protected function execute($container)
    {
        $commandLine = $this->artisanCommandLine();

        // Not a plain "php artisan ..." command (e.g. a raw exec() call to some
        // other binary) — we have nothing safe to run in-process, so fall back
        // to the normal proc_open-based execution.
        if ($commandLine === null) {
            return parent::execute($container);
        }

        try {
            $exitCode = Artisan::call($commandLine);
        } catch (Throwable $e) {
            report($e);

            $exitCode = 1;
        }

        $this->writeOutput(Artisan::output());

        return $exitCode;
    }

    /**
     * Strip the PHP binary + artisan binary prefix Schedule::command() adds,
     * leaving just the command name and its arguments/options.
     */
    protected function artisanCommandLine(): ?string
    {
        if (! is_string($this->command)) {
            return null;
        }

        $prefix = ConsoleApplication::phpBinary().' '.ConsoleApplication::artisanBinary().' ';

        if (! str_starts_with($this->command, $prefix)) {
            return null;
        }

        return substr($this->command, strlen($prefix));
    }

    /**
     * Replicate the shell output redirection normally handled by CommandBuilder,
     * so sendOutputTo()/appendOutputTo()/emailOutputTo() keep working.
     */
    protected function writeOutput(string $output): void
    {
        if ($output === '' || $this->output === $this->getDefaultOutput()) {
            return;
        }

        @file_put_contents(
            $this->output,
            $output,
            $this->shouldAppendOutput ? FILE_APPEND : 0
        );
    }
}
