<?php

namespace Database\Seeders;

use App\Console\Commands\NormalizeStudentNames;
use App\Models\Student;
use Illuminate\Database\Seeder;

class StudentSeeder extends Seeder
{
    public function run(): void
    {
        $students = array_merge(
            $this->gradeSchoolStudents(),
            $this->highSchoolJuniorStudents(),
            $this->highSchoolSeniorStudents(),
            $this->collegeStudents(),
        );

        $qrNumber = $this->nextQrSequence();

        foreach ($students as $row) {
            $fullName = trim($row['firstname'].' '.$row['lastname']);
            $row['normalized_name'] = NormalizeStudentNames::normalizeFullName($fullName);
            $row['role_id'] = null;

            $existing = Student::where('student_id', $row['student_id'])->first();
            if (! $existing) {
                $row['qrcode'] = 'S-'.str_pad((string) $qrNumber, 8, '0', STR_PAD_LEFT);
                $qrNumber++;
            }

            Student::updateOrCreate(
                ['student_id' => $row['student_id']],
                $row
            );
        }
    }

    private function nextQrSequence(): int
    {
        $last = Student::query()
            ->whereNotNull('qrcode')
            ->where('qrcode', 'like', 'S-%')
            ->orderByDesc('id')
            ->value('qrcode');

        if ($last && preg_match('/S-(\d+)/', $last, $matches)) {
            return (int) $matches[1] + 1;
        }

        return 1;
    }

    /** @return list<array<string, mixed>> */
    private function gradeSchoolStudents(): array
    {
        return [
            [
                'student_id' => '2024-GS-K1',
                'firstname' => 'Lucas',
                'lastname' => 'Dizon',
                'middle_initial' => 'T',
                'educational_level' => 'grade_school',
                'course' => 'Section Dahlia',
                'year' => 'Kinder',
                'mobile_number' => '09160000006',
                'birth_date' => '2020-02-10',
            ],
            [
                'student_id' => '2024-GS-K2',
                'firstname' => 'Emma',
                'lastname' => 'Pascual',
                'middle_initial' => 'G',
                'educational_level' => 'grade_school',
                'course' => 'Section Orchid',
                'year' => 'Kinder 2',
                'mobile_number' => '09160000007',
                'birth_date' => '2019-07-18',
            ],
            [
                'student_id' => '2024-GS-001',
                'firstname' => 'Sofia',
                'lastname' => 'Rivera',
                'middle_initial' => 'A',
                'educational_level' => 'grade_school',
                'course' => 'Section Mabini',
                'year' => 'Grade 1',
                'mobile_number' => '09160000001',
                'birth_date' => '2018-01-12',
            ],
            [
                'student_id' => '2024-GS-002',
                'firstname' => 'Ethan',
                'lastname' => 'Gomez',
                'middle_initial' => 'R',
                'educational_level' => 'grade_school',
                'course' => 'Section Rizal',
                'year' => 'Grade 2',
                'mobile_number' => '09160000002',
                'birth_date' => '2017-06-03',
            ],
            [
                'student_id' => '2024-GS-003',
                'firstname' => 'Mia',
                'lastname' => 'Fernandez',
                'middle_initial' => 'L',
                'educational_level' => 'grade_school',
                'course' => 'Section Bonifacio',
                'year' => 'Grade 4',
                'mobile_number' => '09160000003',
                'birth_date' => '2015-09-20',
            ],
            [
                'student_id' => '2024-GS-004',
                'firstname' => 'Noah',
                'lastname' => 'Castillo',
                'middle_initial' => 'D',
                'educational_level' => 'grade_school',
                'course' => 'Section Luna',
                'year' => 'Grade 5',
                'mobile_number' => '09160000004',
                'birth_date' => '2014-11-08',
            ],
            [
                'student_id' => '2024-GS-005',
                'firstname' => 'Aria',
                'lastname' => 'Mendoza',
                'middle_initial' => 'S',
                'educational_level' => 'grade_school',
                'course' => 'Section Aguinaldo',
                'year' => 'Grade 6',
                'mobile_number' => '09160000005',
                'birth_date' => '2013-04-15',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function highSchoolJuniorStudents(): array
    {
        return [
            [
                'student_id' => '2024-HSJ-001',
                'firstname' => 'Luis',
                'lastname' => 'Tan',
                'middle_initial' => 'B',
                'educational_level' => 'high_school_junior',
                'course' => 'STEM',
                'year' => 'Grade 7',
                'mobile_number' => '09170000001',
                'birth_date' => '2012-02-14',
            ],
            [
                'student_id' => '2024-HSJ-002',
                'firstname' => 'Hannah',
                'lastname' => 'Villanueva',
                'middle_initial' => 'C',
                'educational_level' => 'high_school_junior',
                'course' => 'ABM',
                'year' => 'Grade 8',
                'mobile_number' => '09170000002',
                'birth_date' => '2011-08-25',
            ],
            [
                'student_id' => '2024-HSJ-003',
                'firstname' => 'Gabriel',
                'lastname' => 'Ramos',
                'middle_initial' => 'P',
                'educational_level' => 'high_school_junior',
                'course' => 'HUMSS',
                'year' => 'Grade 9',
                'mobile_number' => '09170000003',
                'birth_date' => '2010-12-01',
            ],
            [
                'student_id' => '2024-HSJ-004',
                'firstname' => 'Isabella',
                'lastname' => 'Torres',
                'middle_initial' => 'M',
                'educational_level' => 'high_school_junior',
                'course' => 'GAS',
                'year' => 'Grade 9',
                'mobile_number' => '09170000004',
                'birth_date' => '2010-05-19',
            ],
            [
                'student_id' => '2024-HSJ-005',
                'firstname' => 'Miguel',
                'lastname' => 'Aquino',
                'middle_initial' => 'J',
                'educational_level' => 'high_school_junior',
                'course' => 'STEM',
                'year' => 'Grade 10',
                'mobile_number' => '09170000005',
                'birth_date' => '2009-10-30',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function highSchoolSeniorStudents(): array
    {
        return [
            [
                'student_id' => '2024-HSS-001',
                'firstname' => 'Patricia',
                'lastname' => 'Navarro',
                'middle_initial' => 'E',
                'educational_level' => 'high_school_senior',
                'course' => 'STEM',
                'year' => 'Grade 11',
                'mobile_number' => '09180000001',
                'birth_date' => '2008-03-07',
            ],
            [
                'student_id' => '2024-HSS-002',
                'firstname' => 'Daniel',
                'lastname' => 'Cruz',
                'middle_initial' => 'A',
                'educational_level' => 'high_school_senior',
                'course' => 'ABM',
                'year' => 'Grade 11',
                'mobile_number' => '09180000002',
                'birth_date' => '2008-07-18',
            ],
            [
                'student_id' => '2024-HSS-003',
                'firstname' => 'Angela',
                'lastname' => 'Bautista',
                'middle_initial' => 'R',
                'educational_level' => 'high_school_senior',
                'course' => 'HUMSS',
                'year' => 'Grade 12',
                'mobile_number' => '09180000003',
                'birth_date' => '2007-01-22',
            ],
            [
                'student_id' => '2024-HSS-004',
                'firstname' => 'Carlos',
                'lastname' => 'Domingo',
                'middle_initial' => 'T',
                'educational_level' => 'high_school_senior',
                'course' => 'TVL - ICT',
                'year' => 'Grade 12',
                'mobile_number' => '09180000004',
                'birth_date' => '2007-09-11',
            ],
            [
                'student_id' => '2024-HSS-005',
                'firstname' => 'Bianca',
                'lastname' => 'Salazar',
                'middle_initial' => 'N',
                'educational_level' => 'high_school_senior',
                'course' => 'GAS',
                'year' => 'Grade 12',
                'mobile_number' => '09180000005',
                'birth_date' => '2007-12-05',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function collegeStudents(): array
    {
        return [
            [
                'student_id' => '2024-COL-001',
                'firstname' => 'Juan',
                'lastname' => 'Dela Cruz',
                'middle_initial' => 'M',
                'educational_level' => 'college',
                'course' => 'BSCS',
                'year' => '3rd Year',
                'mobile_number' => '09171234501',
                'birth_date' => '2002-03-15',
            ],
            [
                'student_id' => '2024-COL-002',
                'firstname' => 'Maria',
                'lastname' => 'Santos',
                'middle_initial' => 'L',
                'educational_level' => 'college',
                'course' => 'BSIT',
                'year' => '2nd Year',
                'mobile_number' => '09181234502',
                'birth_date' => '2003-07-22',
            ],
            [
                'student_id' => '2024-COL-003',
                'firstname' => 'Jose',
                'lastname' => 'Reyes',
                'middle_initial' => null,
                'educational_level' => 'college',
                'course' => 'BSED',
                'year' => '4th Year',
                'mobile_number' => '09191234503',
                'birth_date' => '2001-11-08',
            ],
            [
                'student_id' => '2024-COL-004',
                'firstname' => 'Ana',
                'lastname' => 'Garcia',
                'middle_initial' => 'P',
                'educational_level' => 'college',
                'course' => 'BSBA',
                'year' => '1st Year',
                'mobile_number' => '09201234504',
                'birth_date' => '2005-01-30',
            ],
            [
                'student_id' => '2024-COL-005',
                'firstname' => 'Mark',
                'lastname' => 'Lopez',
                'middle_initial' => 'D',
                'educational_level' => 'college',
                'course' => 'BSA',
                'year' => '3rd Year',
                'mobile_number' => '09211234505',
                'birth_date' => '2002-09-12',
            ],
        ];
    }
}

