<?php

use App\Jobs\EvaluatePersonaJob;
use App\Jobs\PostWeeklyReportJob;
use App\Jobs\SyncGainersJob;
use App\Models\Persona;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Dispatch one evaluation job per active persona every 15 minutes during NYSE hours.
Schedule::call(function () {
    $count = Persona::where('is_active', true)->count();
    Log::info('trading:evaluate-personas fired', ['active_personas' => $count]);
    Persona::where('is_active', true)
        ->each(fn (Persona $persona) => EvaluatePersonaJob::dispatch($persona));
})
    ->everyFifteenMinutes()
    ->weekdays()
    ->timezone('America/New_York')
    ->between('9:30', '16:00')
    ->name('trading:evaluate-personas');

// Post weekly summary every Friday at noon MT.
Schedule::job(new PostWeeklyReportJob)
    ->timezone('America/Edmonton')
    ->weeklyOn(5, '12:00')
    ->name('trading:weekly-report');

// Sync top daily equity gainers as candidate tickers — weekdays at 9:00am ET before market open.
Schedule::job(new SyncGainersJob)
    ->weekdays()
    ->timezone('America/New_York')
    ->dailyAt('9:00')
    ->name('trading:sync-gainers');
