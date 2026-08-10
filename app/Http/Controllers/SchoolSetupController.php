<?php

namespace App\Http\Controllers;

use App\Models\GradeSection;
use App\Models\Program;
use App\Models\ProgramCourse;
use App\Models\SchoolStrand;
use App\Models\User;
use App\Support\AdvisoryScope;
use Illuminate\Http\Request;

class SchoolSetupController extends Controller
{
    public function index()
    {
        GradeSection::syncFromStudents();

        $user = auth()->user();
        $canManageCollege = $user && ($user->isSuperAdmin() || $user->role === 'staff');
        $canManageK10 = $user && ($user instanceof User) && (
            $user->isSuperAdmin() || $user->role === 'staff' || $user->role === 'k10_admin'
        );
        $canManageShs = $user && ($user instanceof User) && (
            $user->isSuperAdmin() || $user->role === 'staff' || $user->role === 'shs_admin'
        );

        $programs = $canManageCollege
            ? Program::with(['courses' => fn ($q) => $q->orderBy('course_name')])->orderBy('program_name')->get()
            : collect();

        $gradeLevels = AdvisoryScope::filterGradeList(config('sf2.grade_levels', []), $user);
        $seniorHighGrades = array_values(array_filter(
            GradeSection::seniorHighGrades(),
            fn (string $g) => ! $user || $user->canAccessGradeLevel($g)
        ));
        $basicGrades = array_values(array_filter(
            $gradeLevels,
            fn (string $g) => ! in_array($g, GradeSection::seniorHighGrades(), true)
        ));

        $strandRecords = $canManageShs
            ? SchoolStrand::query()->orderBy('name')->get()
            : collect();
        $shsStrands = $strandRecords->pluck('name')->all();

        $basicSections = $canManageK10
            ? GradeSection::query()
                ->where('strand', '')
                ->when($user && $user->isBandAdmin(), function ($q) use ($basicGrades) {
                    $q->whereIn('grade_level', $basicGrades !== [] ? $basicGrades : ['__none__']);
                })
                ->orderBy('grade_level')
                ->orderBy('section')
                ->get()
                ->groupBy('grade_level')
            : collect();

        $seniorSections = $canManageShs
            ? GradeSection::query()
                ->whereIn('grade_level', $seniorHighGrades !== [] ? $seniorHighGrades : ['__none__'])
                ->where('strand', '!=', '')
                ->orderBy('grade_level')
                ->orderBy('strand')
                ->orderBy('section')
                ->get()
                ->groupBy(fn (GradeSection $row) => $row->grade_level.'|'.$row->strand)
            : collect();

        return view('school_setup.index', compact(
            'programs',
            'gradeLevels',
            'basicGrades',
            'seniorHighGrades',
            'shsStrands',
            'strandRecords',
            'basicSections',
            'seniorSections',
            'canManageCollege',
            'canManageK10',
            'canManageShs',
        ));
    }

    public function storeProgram(Request $request)
    {
        $this->authorizeCollegeSetup();

        $data = $request->validate([
            'program_code' => 'required|string|max:50|unique:programs,program_code',
            'program_name' => 'required|string|max:255',
        ]);

        Program::create([
            'program_code' => $data['program_code'],
            'program_name' => $data['program_name'],
            'total_years' => 4,
        ]);

        return redirect()
            ->route('school-setup.index')
            ->with('success', 'Program added.');
    }

    public function updateProgram(Request $request, Program $program)
    {
        $this->authorizeCollegeSetup();

        $data = $request->validate([
            'program_code' => 'required|string|max:50|unique:programs,program_code,'.$program->id,
            'program_name' => 'required|string|max:255',
        ]);

        $program->update($data);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'id' => $program->id,
                'program_code' => $program->program_code,
                'program_name' => $program->program_name,
            ]);
        }

        return redirect()
            ->route('school-setup.index')
            ->with('success', 'Program updated.');
    }

    public function destroyProgram(Program $program)
    {
        $this->authorizeCollegeSetup();

        $program->delete();

        return response()->json([
            'success' => true,
            'id' => $program->id,
        ]);
    }

    public function storeCourse(Request $request, Program $program)
    {
        $this->authorizeCollegeSetup();

        $data = $request->validate([
            'course_code' => 'required|string|max:50|unique:program_courses,course_code,NULL,id,program_id,'.$program->id,
            'course_name' => 'required|string|max:255',
        ]);

        $course = ProgramCourse::create([
            'program_id' => $program->id,
            'course_code' => $data['course_code'],
            'course_name' => $data['course_name'],
        ]);

        if ($request->ajax() || $request->wantsJson()) {
            return view('school_setup.partials.course_item', compact('course'))->render();
        }

        return redirect()
            ->route('school-setup.index')
            ->with('success', 'Course added.');
    }

    public function updateCourse(Request $request, ProgramCourse $course)
    {
        $this->authorizeCollegeSetup();

        $data = $request->validate([
            'course_code' => 'required|string|max:50',
            'course_name' => 'required|string|max:255',
        ]);

        $course->update($data);

        if ($request->ajax() || $request->wantsJson()) {
            return view('school_setup.partials.course_item', compact('course'))->render();
        }

        return redirect()
            ->route('school-setup.index')
            ->with('success', 'Course updated.');
    }

    public function destroyCourse(Request $request, ProgramCourse $course)
    {
        $this->authorizeCollegeSetup();

        $course->delete();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()
            ->route('school-setup.index')
            ->with('success', 'Course removed.');
    }

    public function storeGradeSection(Request $request)
    {
        $seniorGrades = GradeSection::seniorHighGrades();

        $data = $request->validate([
            'grade_level' => 'required|string|max:64',
            'strand' => 'nullable|string|max:64',
            'section' => 'required|string|max:64',
        ]);

        $strand = trim((string) ($data['strand'] ?? ''));
        $isSenior = in_array($data['grade_level'], $seniorGrades, true);
        $user = $request->user();

        if ($user && ! $user->canAccessGradeLevel($data['grade_level'])) {
            return $this->sectionStoreError($request, 'You cannot manage sections outside your grade access.');
        }

        if ($isSenior && $strand === '') {
            return $this->sectionStoreError($request, 'Strand is required for senior high sections.');
        }

        if (! $isSenior) {
            $strand = '';
        }

        $row = GradeSection::firstOrCreate([
            'grade_level' => $data['grade_level'],
            'strand' => $strand,
            'section' => $data['section'],
        ]);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'id' => $row->id,
                'grade_level' => $row->grade_level,
                'strand' => $row->strand,
                'section' => $row->section,
                'html' => view('school_setup.partials.grade_section_item', ['row' => $row])->render(),
            ]);
        }

        return redirect()
            ->route('school-setup.index', ['tab' => 'sections'])
            ->with('success', 'Section added for '.$data['grade_level'].($strand ? ' ('.$strand.')' : '').'.');
    }

    protected function sectionStoreError(Request $request, string $message)
    {
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return redirect()
            ->route('school-setup.index', ['tab' => 'sections'])
            ->withErrors(['section' => $message]);
    }

    public function destroyGradeSection(GradeSection $gradeSection)
    {
        $user = auth()->user();
        if ($user && ! $user->canAccessGradeLevel($gradeSection->grade_level)) {
            abort(403, 'You cannot remove sections outside your grade access.');
        }

        $gradeSection->delete();

        return response()->json(['success' => true, 'id' => $gradeSection->id]);
    }

    public function storeStrand(Request $request)
    {
        $user = $request->user();
        if ($user && $user->isBandAdmin() && $user->role !== 'shs_admin') {
            abort(403, 'Only SHS Admin or superadmin can manage strands.');
        }

        $data = $request->validate([
            'name' => 'required|string|max:64|unique:school_strands,name',
        ]);

        $strand = SchoolStrand::create(['name' => trim($data['name'])]);
        $seniorHighGrades = GradeSection::seniorHighGrades();
        $htmlByGrade = [];

        foreach ($seniorHighGrades as $grade) {
            $htmlByGrade[$grade] = view('school_setup.partials.strand_block', [
                'grade' => $grade,
                'strand' => $strand->name,
                'strandId' => $strand->id,
                'sections' => collect(),
            ])->render();
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'id' => $strand->id,
                'name' => $strand->name,
                'htmlByGrade' => $htmlByGrade,
            ]);
        }

        return redirect()
            ->route('school-setup.index', ['tab' => 'sections'])
            ->with('success', 'Strand "'.$strand->name.'" added.');
    }

    public function destroyStrand(SchoolStrand $schoolStrand)
    {
        $user = auth()->user();
        if ($user && $user->isBandAdmin() && $user->role !== 'shs_admin') {
            abort(403, 'Only SHS Admin or superadmin can manage strands.');
        }

        $name = $schoolStrand->name;
        GradeSection::query()->where('strand', $name)->delete();
        $schoolStrand->delete();

        return response()->json(['success' => true, 'name' => $name]);
    }

    protected function authorizeCollegeSetup(): void
    {
        $user = auth()->user();
        if (! $user || ! ($user->isSuperAdmin() || $user->role === 'staff')) {
            abort(403, 'College program setup is limited to superadmin and staff.');
        }
    }
}
