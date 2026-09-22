<?php

namespace LaravelInlineScheduler\Scheduling;

use Illuminate\Console\Scheduling\Schedule;

/**
 * Identical to Laravel's Schedule, except exec() (used by command()) builds
 * an InlineEvent instead of the stock Event, so every scheduled Artisan
 * command runs in-process instead of requiring proc_open.
 */
class InlineSchedule extends Schedule
{
    /**
     * @param  string  $command
     * @param  array  $parameters
     * @return \Illuminate\Console\Scheduling\Event
     */
    public function exec($command, array $parameters = [])
    {
        if (count($parameters)) {
            $command .= ' '.$this->compileParameters($parameters);
        }

        $this->events[] = $event = new InlineEvent($this->eventMutex, $command, $this->timezone);

        $this->mergePendingAttributes($event);

        return $event;
    }
}
