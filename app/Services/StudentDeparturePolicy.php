<?php

namespace App\Services;

use App\Enums\EducationalLevel;
use App\Models\Student;
use Carbon\Carbon;

class StudentDeparturePolicy
{
    public function __construct(
        protected AttendancePolicyService $attendancePolicy,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('patron.early_departure.enabled', true);
    }

    public function appliesTo(Student $student): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $levels = config('patron.early_departure.educational_levels', ['grade_school']);
        $studentLevel = $this->resolveLevelValue($student);

        return $studentLevel !== null && in_array($studentLevel, $levels, true);
    }

    /**
     * True when this student must not check out yet (before configured cutoff time).
     */
    public function blocksCheckout(Student $student, ?Carbon $at = null): bool
    {
        if (! $this->appliesTo($student)) {
            return false;
        }

        $at ??= Carbon::now($this->timezone());

        return $at->lt($this->earliestOutToday());
    }

    public function blockMessage(): string
    {
        return (string) config(
            'patron.early_departure.message',
            'Kinder and grade school students cannot check out before 4:00 PM.'
        );
    }

    public function earliestOutLabel(): string
    {
        $time = $this->attendancePolicy->logoutTime();

        return Carbon::today($this->timezone())
            ->setTimeFromTimeString($time)
            ->format('g:i A');
    }

    public function timezone(): string
    {
        return (string) config('patron.early_departure.timezone', 'Asia/Manila');
    }

    private function earliestOutToday(): Carbon
    {
        return Carbon::today($this->timezone())->setTimeFromTimeString($this->attendancePolicy->logoutTime());
    }

    private function resolveLevelValue(Student $student): ?string
    {
        $level = $student->educational_level;

        if ($level instanceof EducationalLevel) {
            return $level->value;
        }

        if (is_string($level) && $level !== '') {
            return $level;
        }

        $raw = $student->getRawOriginal('educational_level');

        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
