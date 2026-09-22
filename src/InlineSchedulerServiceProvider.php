<?php

namespace LaravelInlineScheduler;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LaravelInlineScheduler\Scheduling\InlineSchedule;
use ReflectionMethod;

class InlineSchedulerServiceProvider extends ServiceProvider
{
    /**
     * Replace the framework's Schedule::class singleton with one that builds
     * InlineSchedule/InlineEvent instances, so the stock `schedule:run`,
     * `schedule:list` and `schedule:test` commands keep working unmodified —
     * no changes needed to the app's own schedule() definitions.
     */
    public function register(): void
    {
        $this->app->singleton(Schedule::class, function (Application $app) {
            $kernel = $app->make(ConsoleKernelContract::class);

            $schedule = (new InlineSchedule($this->invokeProtected($kernel, 'scheduleTimezone')))
                ->useCache($this->invokeProtected($kernel, 'scheduleCache'));

            // Populates the schedule via the app's own (possibly legacy)
            // Kernel::schedule() method. On apps without one, this simply
            // calls the framework's no-op default.
            $this->invokeProtected($kernel, 'schedule', [$schedule]);

            return $schedule;
        });
    }

    /**
     * @return mixed
     */
    protected function invokeProtected(object $object, string $method, array $args = [])
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
