<?php

namespace LaravelInlineScheduler\Tests\Fixtures;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel;

/**
 * Mimics a legacy app/Console/Kernel.php that defines its schedule via the
 * protected schedule() method, exactly like the user's real-world project.
 */
class TestKernel extends Kernel
{
    protected $commands = [
        SampleCommand::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command(SampleCommand::class)->everyMinute();
    }
}
