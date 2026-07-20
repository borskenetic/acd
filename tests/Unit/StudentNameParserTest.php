<?php

namespace Tests\Unit;

use App\Support\StudentNameParser;
use PHPUnit\Framework\TestCase;

class StudentNameParserTest extends TestCase
{
    public function test_parses_surname_given_and_all_caps_middle(): void
    {
        $parsed = StudentNameParser::parse('ALLOSO, Tobias APOSTOL');

        $this->assertSame([
            'lastname' => 'Alloso',
            'firstname' => 'Tobias',
            'middle_initial' => 'Apostol',
        ], $parsed);
    }

    public function test_parses_multi_word_given_name_with_middle(): void
    {
        $parsed = StudentNameParser::parse('BERSABAL, Toby Kendric CABAHUG');

        $this->assertSame([
            'lastname' => 'Bersabal',
            'firstname' => 'Toby Kendric',
            'middle_initial' => 'Cabahug',
        ], $parsed);
    }

    public function test_parses_name_without_middle(): void
    {
        $parsed = StudentNameParser::parse('LIMBING, Abby');

        $this->assertSame([
            'lastname' => 'Limbing',
            'firstname' => 'Abby',
            'middle_initial' => null,
        ], $parsed);
    }

    public function test_keeps_title_case_trailing_token_in_firstname(): void
    {
        $parsed = StudentNameParser::parse('ALVIAR, Zeke Liamson Deo');

        $this->assertSame([
            'lastname' => 'Alviar',
            'firstname' => 'Zeke Liamson Deo',
            'middle_initial' => null,
        ], $parsed);
    }

    public function test_does_not_treat_single_letter_as_middle(): void
    {
        $parsed = StudentNameParser::parse('SANTOS, Juan A');

        $this->assertSame([
            'lastname' => 'Santos',
            'firstname' => 'Juan A',
            'middle_initial' => null,
        ], $parsed);
    }

    public function test_returns_null_for_invalid_input(): void
    {
        $this->assertNull(StudentNameParser::parse(''));
        $this->assertNull(StudentNameParser::parse('No Comma Here'));
        $this->assertNull(StudentNameParser::parse(', Only First'));
        $this->assertNull(StudentNameParser::parse('OnlyLast,'));
    }
}
