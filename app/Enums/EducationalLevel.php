<?php

namespace App\Enums;

enum EducationalLevel: string
{
    case GradeSchool = 'grade_school';
    case HighSchoolJunior = 'high_school_junior';
    case HighSchoolSenior = 'high_school_senior';
    case College = 'college';

    public function label(): string
    {
        return match ($this) {
            self::GradeSchool => 'Grade School',
            self::HighSchoolJunior => 'High School (Junior)',
            self::HighSchoolSenior => 'High School (Senior)',
            self::College => 'College',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Template folder key under images/id_templates/ (3 student card designs). */
    public function idCardTemplateKey(): string
    {
        return match ($this) {
            self::GradeSchool => 'grade_school',
            self::HighSchoolJunior, self::HighSchoolSenior => 'high_school',
            self::College => 'college',
        };
    }

    public static function idCardTemplateKeyFor(?string $value): string
    {
        if ($value === null || $value === '') {
            return self::College->idCardTemplateKey();
        }

        $level = self::tryFrom($value);

        return $level?->idCardTemplateKey() ?? self::College->idCardTemplateKey();
    }

    /** Kinder and grade school (all year levels under grade_school). */
    public function restrictsEarlyDeparture(): bool
    {
        return $this === self::GradeSchool;
    }

    /** @return list<string> */
    public static function kinderYearLabels(): array
    {
        return ['Kinder'];
    }

    /** @return list<string> */
    public static function legacyKinderYearLabels(): array
    {
        return ['Kinder 1', 'Kinder 2'];
    }

    public static function isKinderYear(?string $year): bool
    {
        if ($year === null) {
            return false;
        }

        $year = trim($year);

        return in_array($year, self::kinderYearLabels(), true)
            || in_array($year, self::legacyKinderYearLabels(), true)
            || strcasecmp($year, 'Kinder') === 0;
    }
}

