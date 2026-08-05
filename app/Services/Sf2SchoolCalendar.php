<?php

namespace App\Services;

use App\Models\SchoolCalendarDay;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class Sf2SchoolCalendar
{
    /**
     * Attendance / SF2 school days in the month.
     * Defaults to weekdays; honors admin calendar overrides.
     *
     * - holiday / otherwise: never a school day (even on weekdays)
     * - school_day: always a school day (even on weekends, e.g. make-up)
     *
     * @return list<string> Y-m-d dates
     */
    public function schoolDaysInMonth(int $year, int $month, ?int $max = null): array
    {
        $max = $max ?? (int) config('sf2.max_day_columns', 25);
        $tz = config('sf2.timezone', 'Asia/Manila');

        $start = Carbon::create($year, $month, 1, 0, 0, 0, $tz)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $overrides = $this->overridesBetween($start->toDateString(), $end->toDateString());

        $days = [];
        for ($d = $start->copy(); $d->lte($end) && count($days) < $max; $d->addDay()) {
            $key = $d->toDateString();
            $override = $overrides[$key] ?? null;

            if ($override === SchoolCalendarDay::TYPE_HOLIDAY
                || $override === SchoolCalendarDay::TYPE_OTHERWISE) {
                continue;
            }

            if ($override === SchoolCalendarDay::TYPE_SCHOOL_DAY || $d->isWeekday()) {
                $days[] = $key;
            }
        }

        return $days;
    }

    public function dayCount(int $year, int $month): int
    {
        return count($this->schoolDaysInMonth($year, $month));
    }

    /** True when the date counts for attendance (scans, absence, SF2). */
    public function isAttendanceDay(string $date): bool
    {
        $tz = config('sf2.timezone', 'Asia/Manila');
        $day = Carbon::parse($date, $tz)->startOfDay();

        $override = $this->overrideType($day->toDateString());

        if ($override === SchoolCalendarDay::TYPE_HOLIDAY
            || $override === SchoolCalendarDay::TYPE_OTHERWISE) {
            return false;
        }

        if ($override === SchoolCalendarDay::TYPE_SCHOOL_DAY) {
            return true;
        }

        return $day->isWeekday();
    }

    public function isFridayOnlineDay(string $date): bool
    {
        $tz = config('sf2.timezone', 'Asia/Manila');
        $day = Carbon::parse($date, $tz);

        return $day->isFriday() && $this->isAttendanceDay($date);
    }

    protected function overrideType(string $date): ?string
    {
        if (! Schema::hasTable('school_calendar_days')) {
            return null;
        }

        $type = SchoolCalendarDay::query()->whereDate('date', $date)->value('type');

        return is_string($type) ? $type : null;
    }

    /**
     * @return array<string, string> date => type
     */
    protected function overridesBetween(string $from, string $to): array
    {
        if (! Schema::hasTable('school_calendar_days')) {
            return [];
        }

        return SchoolCalendarDay::query()
            ->whereBetween('date', [$from, $to])
            ->pluck('type', 'date')
            ->mapWithKeys(function ($type, $date) {
                $key = $date instanceof \DateTimeInterface
                    ? Carbon::instance($date)->toDateString()
                    : (string) $date;

                return [$key => (string) $type];
            })
            ->all();
    }
}
