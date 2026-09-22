<?php

namespace LaravelInlineScheduler\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use LaravelInlineScheduler\Scheduling\InlineEvent;
use LaravelInlineScheduler\Scheduling\InlineSchedule;
use LaravelInlineScheduler\Tests\Fixtures\SampleCommand;
use LaravelInlineScheduler\Tests\Fixtures\TestKernel;

class InlineSchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SampleCommand::$runCount = 0;

        // Scheduling a command class does not, by itself, register it with
        // Artisan's command list (that's a separate step real apps do via
        // Kernel::commands()/auto-discovery) — do it explicitly here so
        // Artisan::call() can find it by name, just like in a real app.
        $this->app->make(ConsoleKernelContract::class)->registerCommand(new SampleCommand());
    }

    public function test_schedule_singleton_is_inline_schedule(): void
    {
        $this->assertInstanceOf(InlineSchedule::class, $this->app->make(Schedule::class));
    }

    public function test_command_produces_an_inline_event(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $event = $schedule->command(SampleCommand::class);

        $this->assertInstanceOf(InlineEvent::class, $event);
    }

    public function test_running_the_event_executes_the_command_in_process(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $event = $schedule->command(SampleCommand::class);

        $event->run($this->app);

        // A real subprocess would run in an isolated PHP process and could
        // never mutate this process's static state — this only passes if
        // the command actually executed inline, without proc_open.
        $this->assertSame(1, SampleCommand::$runCount);
        $this->assertSame(0, $event->exitCode);
    }

    public function test_failing_command_reports_non_zero_exit_code_and_fires_on_failure(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $failed = false;

        $event = $schedule->command(SampleCommand::class, ['--fail' => true])
            ->onFailure(function () use (&$failed) {
                $failed = true;
            });

        $event->run($this->app);

        $this->assertSame(1, $event->exitCode);
        $this->assertTrue($failed);
    }

    public function test_output_is_written_to_the_configured_destination(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $output = tempnam(sys_get_temp_dir(), 'inline-scheduler-test-');

        $event = $schedule->command(SampleCommand::class)->sendOutputTo($output);

        $event->run($this->app);

        $this->assertStringContainsString('sample output line', file_get_contents($output));

        unlink($output);
    }

    public function test_without_overlapping_mutex_is_still_respected(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $event = $schedule->command(SampleCommand::class)->withoutOverlapping();

        // Simulate another process already holding the lock for this event.
        $event->mutex->create($event);

        $event->run($this->app);

        $this->assertSame(0, SampleCommand::$runCount);

        $event->mutex->forget($event);
    }

    public function test_legacy_kernel_schedule_method_is_populated_automatically(): void
    {
        $this->app->singleton(ConsoleKernelContract::class, TestKernel::class);
        $this->app->forgetInstance(Schedule::class);

        $schedule = $this->app->make(Schedule::class);

        $events = $schedule->events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(InlineEvent::class, $events[0]);

        $events[0]->run($this->app);

        $this->assertSame(1, SampleCommand::$runCount);
    }
}
