<?php

use App\Jobs\EvaluatePersonaJob;
use App\Jobs\PostWeeklyReportJob;
use App\Models\Persona;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dispatch one evaluation job per active persona every 15 minutes during NYSE hours.
Schedule::call(function () {
    Persona::where('is_active', true)
        ->each(fn (Persona $persona) => EvaluatePersonaJob::dispatch($persona));
})
    ->everyFifteenMinutes()
    ->weekdays()
    ->between('9:30', '16:00')
    ->timezone('America/New_York')
    ->name('trading:evaluate-personas')
    ->withoutOverlapping();

// Post weekly summary every Friday at noon MT.
Schedule::job(new PostWeeklyReportJob)
    ->weeklyOn(5, '12:00')
    ->timezone('America/Edmonton')
    ->name('trading:weekly-report')
    ->withoutOverlapping();
