<?php

namespace App\Support;

/**
 * Parses combined student name strings into last / first / middle parts.
 *
 * Expected format (common in school rosters):
 *   "LASTNAME, Given Names MIDDLE"
 * where the surname is before the comma and an optional trailing ALL-CAPS
 * token is treated as the middle name.
 */
class StudentNameParser
{
    /**
     * @return array{lastname: string, firstname: string, middle_initial: ?string}|null
     */
    public static function parse(string $fullName): ?array
    {
        $fullName = trim(preg_replace('/\s+/', ' ', $fullName) ?? '');

        if ($fullName === '') {
            return null;
        }

        if (! str_contains($fullName, ',')) {
            return null;
        }

        [$lastRaw, $restRaw] = array_map('trim', explode(',', $fullName, 2));

        if ($lastRaw === '' || $restRaw === '') {
            return null;
        }

        $tokens = preg_split('/\s+/', $restRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return null;
        }

        $middle = null;
        $givenTokens = $tokens;

        $lastToken = $tokens[array_key_last($tokens)];
        if (count($tokens) >= 2 && self::isAllCapsWord($lastToken)) {
            $middle = self::titleCaseName($lastToken);
            $givenTokens = array_slice($tokens, 0, -1);
        }

        $firstname = self::titleCaseName(implode(' ', $givenTokens));
        $lastname = self::titleCaseName($lastRaw);

        if ($firstname === '' || $lastname === '') {
            return null;
        }

        return [
            'lastname' => $lastname,
            'firstname' => $firstname,
            'middle_initial' => $middle,
        ];
    }

    private static function isAllCapsWord(string $word): bool
    {
        $letters = preg_replace('/[^A-Za-z]/', '', $word) ?? '';

        // Single-letter tokens (e.g. middle initial "A") stay with the given name.
        if (strlen($letters) < 2) {
            return false;
        }

        return $letters === strtoupper($letters);
    }

    private static function titleCaseName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        if ($name === '') {
            return '';
        }

        return collect(explode(' ', strtolower($name)))
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)).mb_substr($part, 1))
            ->implode(' ');
    }
}
