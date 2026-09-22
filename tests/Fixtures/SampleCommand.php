<?php

namespace LaravelInlineScheduler\Tests\Fixtures;

use Illuminate\Console\Command;

class SampleCommand extends Command
{
    protected $signature = 'inline-scheduler-test:sample {--fail}';

    protected $description = 'Fixture command used by the InlineScheduler test suite.';

    public static int $runCount = 0;

    public function handle(): int
    {
        static::$runCount++;

        $this->info('sample output line');

        return $this->option('fail') ? 1 : 0;
    }
}
