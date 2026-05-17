<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class TsxMarketCalendar
{
    public function isTradingDay(?CarbonInterface $date = null): bool
    {
        $date = ($date ?? now())->copy()->startOfDay();

        if ($date->isWeekend()) {
            return false;
        }

        return ! $this->isHoliday($date);
    }

    public function isHoliday(CarbonInterface $date): bool
    {
        $target = $date->format('Y-m-d');

        foreach ($this->holidaysForYear((int) $date->year) as $holiday) {
            if ($holiday->format('Y-m-d') === $target) {
                return true;
            }
        }

        return false;
    }

    /** @return Carbon[] */
    public function holidaysForYear(int $year): array
    {
        return array_merge(
            [
                $this->observed(Carbon::create($year, 1, 1)),   // New Year's Day
                $this->nthMonday($year, 2, 3),                  // Family Day (3rd Mon Feb)
                $this->goodFriday($year),                        // Good Friday
                $this->victoriaDay($year),                       // Victoria Day
                $this->observed(Carbon::create($year, 7, 1)),   // Canada Day
                $this->nthMonday($year, 8, 1),                  // Civic Holiday (1st Mon Aug)
                $this->nthMonday($year, 9, 1),                  // Labour Day (1st Mon Sep)
                $this->nthMonday($year, 10, 2),                 // Thanksgiving (2nd Mon Oct)
            ],
            $this->christmasHolidays($year),
        );
    }

    private function observed(Carbon $date): Carbon
    {
        if ($date->isSaturday()) {
            return $date->copy()->subDay();
        }

        if ($date->isSunday()) {
            return $date->copy()->addDay();
        }

        return $date;
    }

    private function goodFriday(int $year): Carbon
    {
        return Carbon::parse(date('Y-m-d', easter_date($year)))->subDays(2);
    }

    private function victoriaDay(int $year): Carbon
    {
        // Last Monday on or before May 24
        $day = Carbon::create($year, 5, 24);
        while (! $day->isMonday()) {
            $day->subDay();
        }

        return $day;
    }

    private function nthMonday(int $year, int $month, int $n): Carbon
    {
        $first = Carbon::create($year, $month, 1);
        if (! $first->isMonday()) {
            $first->next(Carbon::MONDAY);
        }

        return $first->addWeeks($n - 1);
    }

    /** @return Carbon[] */
    private function christmasHolidays(int $year): array
    {
        $christmas = Carbon::create($year, 12, 25);

        if ($christmas->isSaturday()) {
            // Christmas observed Fri Dec 24, Boxing Day observed Mon Dec 28
            return [Carbon::create($year, 12, 24), Carbon::create($year, 12, 28)];
        }

        if ($christmas->isSunday()) {
            // Christmas observed Mon Dec 26, Boxing Day observed Tue Dec 27
            return [Carbon::create($year, 12, 26), Carbon::create($year, 12, 27)];
        }

        $boxing = Carbon::create($year, 12, 26);

        if ($boxing->isSaturday()) {
            return [$christmas, Carbon::create($year, 12, 28)];
        }

        if ($boxing->isSunday()) {
            return [$christmas, Carbon::create($year, 12, 27)];
        }

        return [$christmas, $boxing];
    }
}
