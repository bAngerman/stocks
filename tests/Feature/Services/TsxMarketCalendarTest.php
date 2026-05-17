<?php

use App\Services\TsxMarketCalendar;
use Carbon\Carbon;

beforeEach(function () {
    $this->calendar = new TsxMarketCalendar;
});

// --- isTradingDay ---

it('treats a regular weekday as a trading day', function () {
    expect($this->calendar->isTradingDay(Carbon::create(2026, 5, 14)))->toBeTrue(); // Thursday
});

it('treats Saturday as not a trading day', function () {
    expect($this->calendar->isTradingDay(Carbon::create(2026, 5, 16)))->toBeFalse();
});

it('treats Sunday as not a trading day', function () {
    expect($this->calendar->isTradingDay(Carbon::create(2026, 5, 17)))->toBeFalse();
});

it('treats Victoria Day as not a trading day', function () {
    expect($this->calendar->isTradingDay(Carbon::create(2026, 5, 18)))->toBeFalse();
});

// --- Holiday correctness ---

it('identifies New Year\'s Day', function () {
    expect($this->calendar->isHoliday(Carbon::create(2026, 1, 1)))->toBeTrue();
});

it('observes New Year\'s Day on Monday when Jan 1 is Sunday', function () {
    // Jan 1, 2023 was a Sunday — observed Jan 2
    expect($this->calendar->isHoliday(Carbon::create(2023, 1, 2)))->toBeTrue()
        ->and($this->calendar->isHoliday(Carbon::create(2023, 1, 1)))->toBeFalse();
});

it('identifies Family Day (3rd Monday in February)', function () {
    expect($this->calendar->isHoliday(Carbon::create(2026, 2, 16)))->toBeTrue();
});

it('identifies Good Friday 2026', function () {
    // Easter 2026 is April 5, so Good Friday is April 3
    expect($this->calendar->isHoliday(Carbon::create(2026, 4, 3)))->toBeTrue();
});

it('identifies Victoria Day 2026 as May 18', function () {
    expect($this->calendar->isHoliday(Carbon::create(2026, 5, 18)))->toBeTrue()
        ->and($this->calendar->isHoliday(Carbon::create(2026, 5, 25)))->toBeFalse();
});

it('identifies Victoria Day 2025 as May 19', function () {
    // May 24, 2025 is a Saturday — last Monday on or before May 24 is May 19
    expect($this->calendar->isHoliday(Carbon::create(2025, 5, 19)))->toBeTrue();
});

it('identifies Canada Day', function () {
    expect($this->calendar->isHoliday(Carbon::create(2026, 7, 1)))->toBeTrue();
});

it('identifies Civic Holiday (1st Monday in August)', function () {
    expect($this->calendar->isHoliday(Carbon::create(2026, 8, 3)))->toBeTrue();
});

it('identifies Labour Day (1st Monday in September)', function () {
    expect($this->calendar->isHoliday(Carbon::create(2026, 9, 7)))->toBeTrue();
});

it('identifies Thanksgiving (2nd Monday in October)', function () {
    expect($this->calendar->isHoliday(Carbon::create(2026, 10, 12)))->toBeTrue();
});

it('identifies Christmas Day', function () {
    expect($this->calendar->isHoliday(Carbon::create(2026, 12, 25)))->toBeTrue();
});

it('observes Boxing Day on Monday when Dec 26 is Saturday', function () {
    // Dec 26, 2026 is a Saturday — observed Monday Dec 28
    expect($this->calendar->isHoliday(Carbon::create(2026, 12, 28)))->toBeTrue()
        ->and($this->calendar->isHoliday(Carbon::create(2026, 12, 26)))->toBeFalse();
});

it('observes Christmas on Monday and Boxing Day on Tuesday when Dec 25 is Sunday', function () {
    // Dec 25, 2022 was a Sunday — Christmas observed Dec 26, Boxing Day observed Dec 27
    expect($this->calendar->isHoliday(Carbon::create(2022, 12, 26)))->toBeTrue()
        ->and($this->calendar->isHoliday(Carbon::create(2022, 12, 27)))->toBeTrue();
});

it('does not mark the day after Boxing Day as a holiday', function () {
    expect($this->calendar->isHoliday(Carbon::create(2026, 12, 29)))->toBeFalse();
});
