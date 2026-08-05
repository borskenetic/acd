<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserAdvisory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AdvisoryScope
{
    public static function isFaculty(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user && $user->role === 'faculty';
    }

    /**
     * @return Collection<int, UserAdvisory>
     */
    public static function assignments(?User $user = null): Collection
    {
        $user ??= auth()->user();

        if (! $user || $user->role !== 'faculty') {
            return collect();
        }

        if ($user->relationLoaded('advisories')) {
            return $user->advisories;
        }

        return $user->advisories()->get();
    }

    public static function hasAdvisory(?User $user = null): bool
    {
        $user ??= auth()->user();

        if (! $user || $user->role !== 'faculty') {
            return true;
        }

        return self::assignments($user)->isNotEmpty()
            || (filled($user->advisory_year) && filled($user->advisory_section));
    }

    /**
     * @return list<array{year: string, section: string, access_level: string}>
     */
    public static function classPairs(?User $user = null, ?string $accessLevel = null): array
    {
        $user ??= auth()->user();
        $pairs = [];

        foreach (self::assignments($user) as $row) {
            if ($accessLevel !== null && $row->access_level !== $accessLevel) {
                continue;
            }
            $pairs[] = [
                'year' => (string) $row->year,
                'section' => (string) $row->section,
                'access_level' => (string) $row->access_level,
            ];
        }

        // Legacy single-column fallback until migration data is fully moved.
        if ($pairs === [] && $user && filled($user->advisory_year) && filled($user->advisory_section)) {
            $pairs[] = [
                'year' => (string) $user->advisory_year,
                'section' => (string) $user->advisory_section,
                'access_level' => UserAdvisory::LEVEL_ADVISER,
            ];
        }

        return $pairs;
    }

    /** @return list<array{year: string, section: string, access_level: string}> */
    public static function managePairs(?User $user = null): array
    {
        return self::classPairs($user, UserAdvisory::LEVEL_ADVISER);
    }

    public static function canViewClass(string $year, string $section, ?User $user = null): bool
    {
        $user ??= auth()->user();
        if (! $user) {
            return false;
        }
        if (in_array($user->role, ['admin', 'staff'], true)) {
            return true;
        }
        if ($user->role !== 'faculty') {
            return false;
        }

        foreach (self::classPairs($user) as $pair) {
            if ($pair['year'] === $year && $pair['section'] === $section) {
                return true;
            }
        }

        return false;
    }

    public static function canManageClass(string $year, string $section, ?User $user = null): bool
    {
        $user ??= auth()->user();
        if (! $user) {
            return false;
        }
        if (in_array($user->role, ['admin', 'staff'], true)) {
            return true;
        }
        if ($user->role !== 'faculty') {
            return false;
        }

        foreach (self::managePairs($user) as $pair) {
            if ($pair['year'] === $year && $pair['section'] === $section) {
                return true;
            }
        }

        return false;
    }

    public static function canManageAnyClass(?User $user = null): bool
    {
        $user ??= auth()->user();
        if (! $user) {
            return false;
        }
        if (in_array($user->role, ['admin', 'staff'], true)) {
            return true;
        }
        if ($user->role !== 'faculty') {
            return false;
        }

        return self::managePairs($user) !== [];
    }

    /**
     * Limit student queries to faculty class assignments.
     */
    public static function applyToStudents(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user || $user->role !== 'faculty') {
            return $query;
        }

        $pairs = self::classPairs($user);
        if ($pairs === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($pairs) {
            foreach ($pairs as $i => $pair) {
                $method = $i === 0 ? 'where' : 'orWhere';
                $outer->{$method}(function (Builder $q) use ($pair) {
                    $q->where('year', $pair['year'])->where('section', $pair['section']);
                });
            }
        });
    }

    /**
     * Limit students faculty can mutate (adviser classes only).
     */
    public static function applyToManageableStudents(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user || $user->role !== 'faculty') {
            return $query;
        }

        $pairs = self::managePairs($user);
        if ($pairs === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($pairs) {
            foreach ($pairs as $i => $pair) {
                $method = $i === 0 ? 'where' : 'orWhere';
                $outer->{$method}(function (Builder $q) use ($pair) {
                    $q->where('year', $pair['year'])->where('section', $pair['section']);
                });
            }
        });
    }

    public static function applyToAttendanceLogs(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user || $user->role !== 'faculty') {
            return $query;
        }

        return $query->whereHas('student', function (Builder $s) use ($user) {
            self::applyToStudents($s, $user);
        });
    }

    public static function applyToSf2Reports(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user || $user->role !== 'faculty') {
            return $query;
        }

        $pairs = self::classPairs($user);
        if ($pairs === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($pairs) {
            foreach ($pairs as $i => $pair) {
                $method = $i === 0 ? 'where' : 'orWhere';
                $outer->{$method}(function (Builder $q) use ($pair) {
                    $q->where('grade_level', $pair['year'])->where('section', $pair['section']);
                });
            }
        });
    }

    public static function applyToPendingStudents(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user || $user->role !== 'faculty') {
            return $query;
        }

        // Advisers only — subject teachers do not approve registrations.
        return self::applyToManageableStudents($query, $user);
    }

    public static function canAccessStudent(object $student, ?User $user = null): bool
    {
        $user ??= auth()->user();

        if (! $user) {
            return false;
        }

        if (in_array($user->role, ['admin', 'staff'], true)) {
            return true;
        }

        if ($user->role !== 'faculty') {
            return false;
        }

        $year = is_string($student->year ?? null) ? $student->year : '';
        $section = is_string($student->section ?? null) ? $student->section : '';

        return self::canViewClass($year, $section, $user);
    }

    public static function canMutateStudent(object $student, ?User $user = null): bool
    {
        $user ??= auth()->user();

        if (! $user) {
            return false;
        }

        if (in_array($user->role, ['admin', 'staff'], true)) {
            return true;
        }

        if ($user->role !== 'faculty') {
            return false;
        }

        $year = is_string($student->year ?? null) ? $student->year : '';
        $section = is_string($student->section ?? null) ? $student->section : '';

        return self::canManageClass($year, $section, $user);
    }

    /**
     * Force faculty-created students into an allowed adviser class.
     * If they only manage one class, fill it. If multiple, keep their choice when valid.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function enforceStudentYearSection(array $data, ?User $user = null): array
    {
        $user ??= auth()->user();

        if (! $user || $user->role !== 'faculty') {
            return $data;
        }

        $manage = self::managePairs($user);
        if ($manage === []) {
            return $data;
        }

        if (count($manage) === 1) {
            $data['year'] = $manage[0]['year'];
            $data['section'] = $manage[0]['section'];

            return $data;
        }

        $year = is_string($data['year'] ?? null) ? $data['year'] : '';
        $section = is_string($data['section'] ?? null) ? $data['section'] : '';
        if (! self::canManageClass($year, $section, $user)) {
            $data['year'] = $manage[0]['year'];
            $data['section'] = $manage[0]['section'];
        }

        return $data;
    }

    public static function displayName(?User $user = null): string
    {
        $user ??= auth()->user();
        if (! $user) {
            return '';
        }

        return trim(($user->fname ?? '').' '.($user->lname ?? '')) ?: (string) $user->email;
    }

    public static function advisoryLabels(?User $user = null): string
    {
        $pairs = self::classPairs($user);
        if ($pairs === []) {
            return '—';
        }

        return collect($pairs)
            ->map(function (array $p) {
                $tag = $p['access_level'] === UserAdvisory::LEVEL_ADVISER ? 'Adv' : 'Subj';

                return $p['year'].' · '.$p['section'].' ['.$tag.']';
            })
            ->implode('; ');
    }

    /**
     * Year options limited to faculty classes (for forms/SMS).
     *
     * @return list<string>
     */
    public static function yearOptions(?User $user = null): array
    {
        return array_values(array_unique(array_column(self::classPairs($user), 'year')));
    }

    /**
     * @return array<string, list<string>>
     */
    public static function sectionsByYear(?User $user = null): array
    {
        $map = [];
        foreach (self::classPairs($user) as $pair) {
            $map[$pair['year']] ??= [];
            if (! in_array($pair['section'], $map[$pair['year']], true)) {
                $map[$pair['year']][] = $pair['section'];
            }
        }

        return $map;
    }
}
