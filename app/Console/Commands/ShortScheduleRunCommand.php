<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use React\EventLoop\Factory;
use Spatie\ShortSchedule\ShortSchedule;

class ShortScheduleRunCommand extends Command
{
    protected $signature = 'short-schedule:run';

    protected $description = 'Run the short scheduled commands';

    public function handle()
    {
        $loop = Factory::create();

        (new ShortSchedule($loop))->registerCommands()->run();
    }
}
