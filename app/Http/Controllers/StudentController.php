<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\Student;
use App\Models\PendingStudent;
use App\Models\Program;
use App\Models\GradeSection;
use App\Models\StudentEditRequest;
use App\Console\Commands\NormalizeStudentNames;
use App\Exports\StudentsContactTemplateExport;
use App\Exports\StudentsImportTemplateExport;
use App\Exports\StudentsListExport;
use App\Exports\StudentsRecordsTemplateExport;
use App\Exports\StudentsRfidTemplateExport;
use App\Exports\StudentsSexTemplateExport;
use App\Imports\StudentsContactImport;
use App\Imports\StudentsImport;
use App\Imports\StudentsRecordsImport;
use App\Imports\StudentsRfidImport;
use App\Imports\StudentsSexImport;
use App\Services\BulkIdCardService;
use App\Support\PatronOptions;
use App\Support\SchoolSetupOptions;
use App\Support\TableColumns;
use App\Support\AdvisoryScope;
use App\Services\ActivityLogger;
use App\Services\StudentConsecutiveAttendanceService;
use App\Services\AttendancePolicyService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;


class StudentController extends Controller
{
    private function programList()
    {
        if (! Schema::hasTable('programs')) {
            return collect();
        }

        return Program::orderBy('program_name')->get();
    }

    // Show all students
    public function index(
        Request $request,
        StudentConsecutiveAttendanceService $streaks,
        AttendancePolicyService $policy,
    ) {
        $programs = $this->programList();
        $students = $this->filteredStudentsQuery($request)
            ->orderBy('lastname', 'asc')
            ->paginate(15)
            ->appends($request->all());

        $user = Auth::user();
        $advisoryNotice = null;
        if ($user && $user->role === 'faculty') {
            $advisoryNotice = $user->hasAdvisoryClass()
                ? 'Your classes: '.$user->advisoryLabel()
                : 'Your account has no class assignments. Ask an admin to assign grade/section access.';
        }

        $streakCounts = $streaks->countsForStudents($students->items());
        $lateThreshold = $policy->consecutiveLateThreshold();
        $absentThreshold = $policy->consecutiveAbsentThreshold();

        return view('students.students', compact(
            'students',
            'programs',
            'advisoryNotice',
            'streakCounts',
            'lateThreshold',
            'absentThreshold',
        ));
    }

    private function filteredStudentsQuery(Request $request)
    {
        $query = Student::query();
        AdvisoryScope::applyToStudents($query);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('lastname', 'like', "%{$search}%")
                    ->orWhere('firstname', 'like', "%{$search}%")
                    ->orWhere('course', 'like', "%{$search}%")
                    ->orWhere('qrcode', 'like', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%")
                    ->orWhere('lrn', 'like', "%{$search}%");
            });
        }

        if ($request->filled('course')) {
            $query->where('course', $request->course);
        }

        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }

        if ($request->filled('educational_level')) {
            $query->where('educational_level', $request->educational_level);
        }

        if ($request->filled('program_id')) {
            $query->where('course', $request->program_id);
        }

        return $query;
    }

    public function downloadImportTemplate()
    {
        return Excel::download(
            new StudentsImportTemplateExport,
            'students_import_template.xlsx'
        );
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls|max:10240',
        ]);

        try {
            Excel::import(new StudentsImport, $request->file('file'));
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $messages = collect($e->failures())
                ->map(fn ($failure) => 'Row '.$failure->row().': '.implode(' ', $failure->errors()))
                ->all();

            return back()->withErrors(['file' => $messages]);
        }

        $sectionsAdded = GradeSection::syncFromStudents();
        $message = 'Students imported successfully.';
        if ($sectionsAdded > 0) {
            $message .= " {$sectionsAdded} grade section(s) added to school setup.";
        }

        return back()->with('success', $message);
    }

    public function downloadRfidTemplate()
    {
        return Excel::download(
            new StudentsRfidTemplateExport,
            'students_rfid_update_template.xlsx'
        );
    }

    public function importRfid(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls|max:10240',
        ]);

        $import = new StudentsRfidImport;
        Excel::import($import, $request->file('file'));

        $report = $import->report();
        $message = 'RFID update complete: '.$report['updated'].' student(s) updated.';

        if ($report['skipped'] > 0) {
            $message .= ' '.$report['skipped'].' row(s) skipped.';
        }

        return back()
            ->with('success', $message)
            ->with('rfid_import_report', $report);
    }

    public function downloadSexTemplate()
    {
        return Excel::download(
            new StudentsSexTemplateExport,
            'students_sex_update_template.xlsx'
        );
    }

    public function importSex(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls|max:10240',
        ]);

        $import = new StudentsSexImport;
        Excel::import($import, $request->file('file'));

        $report = $import->report();
        $message = 'Gender update complete: '.$report['updated'].' student(s) updated.';

        if ($report['unchanged'] > 0) {
            $message .= ' '.$report['unchanged'].' already correct.';
        }
        if ($report['skipped'] > 0) {
            $message .= ' '.$report['skipped'].' row(s) skipped.';
        }

        return back()
            ->with('success', $message)
            ->with('sex_import_report', $report);
    }

    public function downloadContactTemplate()
    {
        return Excel::download(
            new StudentsContactTemplateExport,
            'students_contact_update_template.xlsx'
        );
    }

    public function importContact(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls|max:20480',
        ]);

        $import = new StudentsContactImport;
        $import->importFile($request->file('file'));

        $report = $import->report();
        $message = 'Contact update complete: '.$report['updated'].' student(s) updated.';

        if ($report['unchanged'] > 0) {
            $message .= ' '.$report['unchanged'].' already up to date.';
        }
        if ($report['skipped'] > 0) {
            $message .= ' '.$report['skipped'].' row(s) skipped.';
        }

        return back()
            ->with('success', $message)
            ->with('contact_import_report', $report);
    }

    public function downloadRecordsTemplate()
    {
        return Excel::download(
            new StudentsRecordsTemplateExport,
            'students_records_update_template.xlsx'
        );
    }

    public function importRecords(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls|max:20480',
        ]);

        $import = new StudentsRecordsImport;
        Excel::import($import, $request->file('file'));

        $report = $import->report();
        $message = 'Records update complete: '.$report['updated'].' student(s) updated.';

        if ($report['photos_synced'] > 0) {
            $message .= ' '.$report['photos_synced'].' profile photo path(s) set from RecordID.';
        }
        if ($report['unchanged'] > 0) {
            $message .= ' '.$report['unchanged'].' already up to date.';
        }
        if ($report['skipped'] > 0) {
            $message .= ' '.$report['skipped'].' row(s) skipped.';
        }

        return back()
            ->with('success', $message)
            ->with('records_import_report', $report);
    }

    public function bulkDownloadIds(Request $request, BulkIdCardService $bulkIds)
    {
        $students = $this->filteredStudentsQuery($request)->orderBy('lastname')->get();

        if ($students->isEmpty()) {
            return back()->with('error', 'No students match the current filters.');
        }

        if ($students->count() > BulkIdCardService::MAX_BULK_IDS) {
            return back()->with(
                'error',
                'Too many students ('.$students->count().'). Narrow filters to '.BulkIdCardService::MAX_BULK_IDS.' or fewer.'
            );
        }

        set_time_limit(max(120, $students->count() * 3));

        return $bulkIds->downloadStudentsZip($students);
    }

    public function export(Request $request)
    {
        $paginated = $this->filteredStudentsQuery($request)
            ->orderBy('lastname', 'asc')
            ->paginate(15)
            ->appends($request->query());

        $rows = collect($paginated->items());

        if ($rows->isEmpty()) {
            return back()->with('error', 'No students on this page to export.');
        }

        $filename = 'students_page_'.$paginated->currentPage().'_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(new StudentsListExport($rows), $filename);
    }

    // Show form to create new student
    public function create()
    {
        $user = Auth::user();
        if ($user && $user->role === 'faculty' && ! AdvisoryScope::canManageAnyClass($user)) {
            return redirect()->route('students.index')
                ->with('error', 'Only class advisers can register students. Ask an admin for adviser access on a grade/section.');
        }

        $programs = $this->programList();
        $schoolSetup = SchoolSetupOptions::registrationData();
        $facultyManageClasses = $user && $user->role === 'faculty'
            ? AdvisoryScope::managePairs($user)
            : [];

        return view('students.create', compact('programs', 'schoolSetup', 'facultyManageClasses'));
    }

    // Store new student
    public function store(Request $request, ActivityLogger $activityLogger)
    {
        $user = Auth::user();
        if ($user && $user->role === 'faculty' && ! AdvisoryScope::canManageAnyClass($user)) {
            return redirect()->route('students.index')
                ->with('error', 'Only class advisers can register students.');
        }

        // Validation
        $validated = $request->validate([
            'student_id' => 'required|string|max:255|unique:students,student_id',
            'lrn' => 'nullable|string|max:32|unique:students,lrn',
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'middle_initial' => 'nullable|string|max:255',
            'birth_date' => 'nullable|date',
            'educational_level' => PatronOptions::educationalLevelRule(),
            'course' => 'required_if:educational_level,college|required_if:educational_level,high_school_senior|nullable|string|max:255',
            'year' => [
                'required',
                'string',
                'max:255',
                Rule::in(PatronOptions::yearOptionsFor($request->input('educational_level'))),
            ],
            'section' => 'nullable|string|max:64',
            'sex' => 'nullable|in:male,female',
            'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png|max:4096',
            'student_signature' => 'nullable|string', // base64
            'mobile_number' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'emergency_person' => 'nullable|string|max:255',
            'emergency_relationship' => 'nullable|string|max:255',
            'emergency_number' => 'nullable|string|max:20',
            'emergency_address' => 'nullable|string',
            'rfid' => 'nullable|string|max:255|unique:students,rfid',
        ]);

        $validated = AdvisoryScope::enforceStudentYearSection($validated, $user);

        if ($user && $user->role === 'faculty'
            && ! AdvisoryScope::canManageClass((string) $validated['year'], (string) ($validated['section'] ?? ''), $user)) {
            return back()->withInput()->withErrors([
                'year' => 'You can only register students for classes where you are the adviser.',
            ]);
        }

        // Handle profile picture upload
        if ($request->hasFile('profile_picture')) {
            $file = $request->file('profile_picture');
            $filename = time() . '_profile_' . preg_replace('/\s+/', '_', $file->getClientOriginalName());
            $file->move(base_path('images/profile_pictures'), $filename);
            $validated['profile_picture'] = 'images/profile_pictures/' . $filename;
        }

        // Handle signature (base64)
        if (!empty($validated['student_signature']) && str_starts_with($validated['student_signature'], 'data:')) {

            [$meta, $contents] = explode(',', $validated['student_signature'], 2);

            $ext = 'png';
            if (preg_match('/data:image\/(jpeg|jpg)/i', $meta)) {
                $ext = 'jpg';
            }

            $sigName = time() . '_sig.' . $ext;

            if (!file_exists(base_path('images/student_signatures'))) {
                mkdir(base_path('images/student_signatures'), 0755, true);
            }

            file_put_contents(
                base_path('images/student_signatures/' . $sigName),
                base64_decode($contents)
            );

            $validated['student_signature'] = 'images/student_signatures/' . $sigName;
        }

        // Generate QR code (auto like S-00000001)
        $last = Student::orderBy('id', 'desc')->first();
        $nextNumber = 1;

        if ($last && !empty($last->qrcode) && str_starts_with($last->qrcode, 'S-')) {
            $lastNum = intval(Str::after($last->qrcode, 'S-'));
            $nextNumber = $lastNum + 1;
        }

        $validated['qrcode'] = 'S-' . str_pad($nextNumber, 8, '0', STR_PAD_LEFT);
        $validated['normalized_name'] = NormalizeStudentNames::normalizeFullName(
            $validated['firstname'].' '.$validated['lastname']
        );
        $validated['lrn'] = filled($validated['lrn'] ?? null) ? $validated['lrn'] : null;

        Student::create($validated);

        if ($user && in_array($user->role, ['faculty', 'staff'], true)) {
            $activityLogger->record(
                action: 'students.store',
                summary: 'Registered student '.$validated['lastname'].', '.$validated['firstname'].' ('.$validated['year'].' · '.($validated['section'] ?? '—').')',
                method: 'POST',
                routeName: 'students.store',
                url: $request->fullUrl(),
                user: $user,
                ipAddress: $request->ip(),
                userAgent: (string) $request->userAgent(),
                properties: [
                    'student_id' => $validated['student_id'],
                    'year' => $validated['year'] ?? null,
                    'section' => $validated['section'] ?? null,
                ],
            );
        }

        return redirect()->route('students.index')->with('success', 'Student Registered Successfully!');
    }

    // Edit form
    public function edit($id)
    {
        $student = Student::findOrFail($id);
        if (! AdvisoryScope::canMutateStudent($student)) {
            abort(403, 'You do not have permission to edit this student (adviser access required for faculty).');
        }

        $programs = $this->programList();
        $schoolSetup = SchoolSetupOptions::registrationData();
        $facultyManageClasses = Auth::user() && Auth::user()->role === 'faculty'
            ? AdvisoryScope::managePairs(Auth::user())
            : [];

        return view('students.edit', compact('student', 'programs', 'schoolSetup', 'facultyManageClasses'));
    }

    // Update student
    public function update(Request $request, $id, ActivityLogger $activityLogger)
    {
        $student = Student::findOrFail($id);
        if (! AdvisoryScope::canMutateStudent($student)) {
            abort(403, 'You do not have permission to edit this student (adviser access required for faculty).');
        }
    
        $validated = $request->validate([
            'student_id' => 'required|string|max:255|unique:students,student_id,' . $id,
            'lrn' => 'nullable|string|max:32|unique:students,lrn,' . $id,
            'lastname' => 'required|string|max:255',
            'firstname' => 'required|string|max:255',
            'middle_initial' => 'nullable|string|max:255',
            'birth_date' => 'nullable|date',
            'educational_level' => PatronOptions::educationalLevelRule(),
            'course' => 'required_if:educational_level,college|required_if:educational_level,high_school_senior|nullable|string|max:255',
            'year' => [
                'required',
                'string',
                'max:255',
                Rule::in(PatronOptions::yearOptionsFor($request->input('educational_level'))),
            ],
            'section' => 'nullable|string|max:64',
            'sex' => 'nullable|in:male,female',

            'mobile_number' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'emergency_person' => 'nullable|string|max:255',
            'emergency_relationship' => 'nullable|string|max:255',
            'emergency_number' => 'nullable|string|max:20',
            'emergency_address' => 'nullable|string',
            'rfid' => 'nullable|string|max:255|unique:students,rfid,'.$id,
    
            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'student_signature' => 'nullable|string',
        ]);

        $validated = AdvisoryScope::enforceStudentYearSection($validated);
    
        if (Auth::user() && Auth::user()->role === 'faculty'
            && ! AdvisoryScope::canManageClass((string) $validated['year'], (string) ($validated['section'] ?? ''), Auth::user())) {
            return back()->withInput()->withErrors([
                'year' => 'You can only keep students in classes where you are the adviser.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | PROFILE PICTURE
        |--------------------------------------------------------------------------
        */
        if ($request->hasFile('profile_picture')) {
    
            if ($student->profile_picture && file_exists(base_path($student->profile_picture))) {
                unlink(base_path($student->profile_picture));
            }
    
            $image = $request->file('profile_picture');
            $filename = time() . '_' . preg_replace('/\s+/', '_', $image->getClientOriginalName());
            $image->move(base_path('images/profile_pictures'), $filename);
    
            $validated['profile_picture'] = 'images/profile_pictures/' . $filename;
        }
    
        /*
        |--------------------------------------------------------------------------
        | SIGNATURE (ONLY IF NEW ONE DRAWN)
        |--------------------------------------------------------------------------
        */
        if (!empty($validated['student_signature']) && str_starts_with($validated['student_signature'], 'data:')) {
    
            [$meta, $contents] = explode(',', $validated['student_signature'], 2);
    
            $ext = 'png';
            if (preg_match('/data:image\/(jpeg|jpg)/i', $meta)) {
                $ext = 'jpg';
            }
    
            if (!file_exists(base_path('images/student_signatures'))) {
                mkdir(base_path('images/student_signatures'), 0755, true);
            }
    
            $sigName = time() . '_sig.' . $ext;
    
            file_put_contents(
                base_path('images/student_signatures/' . $sigName),
                base64_decode($contents)
            );
    
            $validated['student_signature'] = 'images/student_signatures/' . $sigName;
        } else {
            // Prevent overwriting existing signature with null
            unset($validated['student_signature']);
        }
    
        /*
        |--------------------------------------------------------------------------
        | DO NOT TOUCH QR CODE
        |--------------------------------------------------------------------------
        */
        unset($validated['qrcode']);

        $validated['normalized_name'] = NormalizeStudentNames::normalizeFullName(
            $validated['firstname'].' '.$validated['lastname']
        );
        $validated['lrn'] = filled($validated['lrn'] ?? null) ? $validated['lrn'] : null;

        $before = $student->only(['student_id', 'firstname', 'lastname', 'year', 'section']);
        $student->update($validated);

        $user = Auth::user();
        if ($user && in_array($user->role, ['faculty', 'staff'], true)) {
            $activityLogger->record(
                action: 'students.update',
                summary: 'Updated student '.$student->lastname.', '.$student->firstname.' (#'.$student->id.')',
                method: 'PUT',
                routeName: 'students.update',
                url: $request->fullUrl(),
                user: $user,
                ipAddress: $request->ip(),
                userAgent: (string) $request->userAgent(),
                properties: [
                    'student_db_id' => $student->id,
                    'before' => $before,
                    'after' => $student->only(['student_id', 'firstname', 'lastname', 'year', 'section']),
                ],
            );
        }
    
        return redirect()
            ->route('students.index')
            ->with('success', 'Student updated successfully!');
    }


    // Delete student
    public function destroy(Request $request, $id, ActivityLogger $activityLogger)
    {
        $student = Student::findOrFail($id);
        if (! AdvisoryScope::canMutateStudent($student)) {
            abort(403, 'You do not have permission to delete this student (adviser access required for faculty).');
        }

        $snapshot = [
            'id' => $student->id,
            'student_id' => $student->student_id,
            'name' => $student->lastname.', '.$student->firstname,
            'year' => $student->year,
            'section' => $student->section,
        ];

        if ($student->profile_picture) {
            Storage::disk('public')->delete($student->profile_picture);
        }

        $student->delete();

        $user = Auth::user();
        if ($user && in_array($user->role, ['faculty', 'staff'], true)) {
            $activityLogger->record(
                action: 'students.destroy',
                summary: 'Deleted student '.$snapshot['name'].' (#'.$snapshot['id'].')',
                method: 'DELETE',
                routeName: 'students.destroy',
                url: $request->fullUrl(),
                user: $user,
                ipAddress: $request->ip(),
                userAgent: (string) $request->userAgent(),
                properties: $snapshot,
            );
        }

        return redirect()->route('students.index')->with('success', 'Student Deleted Successfully!');
    }

    public function show($id)
    {
        $student = Student::findOrFail($id);
        return view('students.show', compact('student'));
    }

    // Pending list
    public function pending()
    {
        $query = PendingStudent::query()->orderBy('lastname');
        AdvisoryScope::applyToPendingStudents($query);
        $pendingStudents = $query->get();

        return view('students.pending', compact('pendingStudents'));
    }

    /**
     * Build student row for approval; only includes columns that exist (legacy DB safe).
     */
    private function buildStudentFromPending(PendingStudent $pending, string $qrcode): array
    {
        $data = [
            'student_id' => $pending->student_id,
            'lastname' => $pending->lastname,
            'firstname' => $pending->firstname,
            'middle_initial' => $pending->middle_initial,
            'blood_type' => $pending->blood_type,
            'course' => $pending->course,
            'year' => $pending->year,
            'section' => $pending->section,
            'sex' => $pending->sex,
            'educational_level' => $pending->educational_level?->value
                ?? $pending->educational_level
                ?? 'college',
            'mobile_number' => $pending->mobile_number,
            'emergency_person' => $pending->emergency_person,
            'emergency_relationship' => $pending->emergency_relationship,
            'emergency_number' => $pending->emergency_number,
            'emergency_address' => $pending->emergency_address,
            'address' => $pending->address,
            'profile_picture' => $pending->profile_picture,
            'student_signature' => $pending->student_signature,
            'qrcode' => $qrcode,
        ];

        if (Schema::hasColumn('students', 'birth_date')) {
            $data['birth_date'] = $pending->birth_date;
        } elseif (Schema::hasColumn('students', 'birthday')) {
            $data['birthday'] = $pending->birth_date;
        }

        if (Schema::hasColumn('students', 'normalized_name')) {
            $data['normalized_name'] = NormalizeStudentNames::normalizeFullName(
                trim($pending->firstname.' '.$pending->lastname)
            );
        }

        return TableColumns::filter('students', $data);
    }

    // Approve pending student → move to students table
    public function approve($id)
    {
        try {
            DB::transaction(function () use ($id) {
                $pending = PendingStudent::findOrFail($id);

                if (! AdvisoryScope::canManageClass(
                    (string) ($pending->year ?? ''),
                    (string) ($pending->section ?? ''),
                    Auth::user()
                )) {
                    throw new \Exception('You can only approve students for your adviser classes.');
                }

                $last = Student::lockForUpdate()->orderBy('id', 'desc')->first();
                $nextNumber = 1;

                if ($last && $last->qrcode && str_starts_with((string) $last->qrcode, 'S-')) {
                    $nextNumber = intval(substr($last->qrcode, 2)) + 1;
                }

                $qrcode = 'S-' . str_pad((string) $nextNumber, 8, '0', STR_PAD_LEFT);

                $schoolId = trim((string) ($pending->student_id ?? ''));
                if ($schoolId !== '' && Student::where('student_id', $schoolId)->exists()) {
                    throw new \Exception('Student ID already exists in the students table.');
                }

                $payload = $this->buildStudentFromPending($pending, $qrcode);

                if ($payload === []) {
                    throw new \Exception('Students table is missing required columns. Run migrations or add missing fields.');
                }

                if (! isset($payload['qrcode'])) {
                    throw new \Exception('Students table is missing the qrcode column.');
                }

                Student::create($payload);
                $pending->delete();
            });

            return back()->with('success', 'Student approved and added to the students table.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    // Reject pending student
    public function reject($id)
    {
        $pending = PendingStudent::findOrFail($id);

        if (! AdvisoryScope::canManageClass(
            (string) ($pending->year ?? ''),
            (string) ($pending->section ?? ''),
            Auth::user()
        )) {
            abort(403, 'You can only reject students for your adviser classes.');
        }

        $pending->delete();

        return back()->with('success', 'Registration rejected.');
    }


    public function profile($qrcode)
    {
        $student = Student::where('qrcode', $qrcode)->firstOrFail();
        session(['student_id' => $student->id]);

        $program = Schema::hasTable('programs')
            ? Program::where('program_code', $student->course)->first()
            : null;
        $programs = $this->programList();

        return view('students.profile', compact(
            'student',
            'program',
            'programs',
        ));
    }

    public function submitEditRequest(Request $request)
    {
        $student = Student::findOrFail($request->student_id);

        // Prevent multiple pending
        if ($student->editRequests()->where('status', 'pending')->exists()) {
            return back()->with('error', 'You already have a pending request.');
        }

        $request->validate([
            'lastname' => 'required|string|max:255',
            'firstname' => 'required|string|max:255',
            'middle_initial' => 'nullable|string|max:255',
            'birthday' => 'nullable|date',
            'program_id' => 'nullable|exists:programs,id',
            'year' => 'nullable|string|max:10',

            'mobile_number' => 'nullable|string|max:20',
            'address' => 'nullable|string',

            'emergency_person' => 'nullable|string|max:255',
            'emergency_relationship' => 'nullable|string|max:255',
            'emergency_number' => 'nullable|string|max:20',
            'emergency_address' => 'nullable|string',

            'profile_picture' => 'nullable|image|max:2048'
        ]);

        $photoPath = null;

        if ($request->hasFile('profile_picture')) {
        
            $image = $request->file('profile_picture');
            $filename = time() . '_' . preg_replace('/\s+/', '_', $image->getClientOriginalName());
        
            $image->move(base_path('images/edits'), $filename);
        
            $photoPath = 'images/edits/' . $filename;
        }


        StudentEditRequest::create([
            'student_id' => $student->id,
            'lastname' => $request->lastname,
            'firstname' => $request->firstname,
            'middle_initial' => $request->middle_initial,
            'birthday' => $request->birthday,
            'program_id' => $request->program_id,
            'year' => $request->year,
            'mobile_number' => $request->mobile_number,
            'address' => $request->address,
            'emergency_person' => $request->emergency_person,
            'emergency_relationship' => $request->emergency_relationship,
            'emergency_number' => $request->emergency_number,
            'emergency_address' => $request->emergency_address,
            'profile_picture' => $photoPath,
        ]);

        return back()->with('success', 'Edit request submitted for approval.');
    }

    public function approveRequest($id)
    {
        $req = StudentEditRequest::findOrFail($id);
        $student = $req->student;
    
        // Handle profile picture transfer
        $newProfilePath = $student->profile_picture;
    
        if ($req->profile_picture) {
    
            // Delete old profile picture if exists
            if ($student->profile_picture && file_exists(base_path($student->profile_picture))) {
                unlink(base_path($student->profile_picture));
            }
    
            $newProfilePath = $req->profile_picture;
        }
    
        // If program_id maps to program_code
        $programCode = $student->course;
        if ($req->program_id) {
            $program = Program::find($req->program_id);
            $programCode = $program ? $program->program_code : $student->course;
        }
    
        $student->update([
            'lastname' => $req->lastname,
            'firstname' => $req->firstname,
            'middle_initial' => $req->middle_initial,
            'birthday' => $req->birthday,
            'course' => $programCode,
            'year' => $req->year,
            'mobile_number' => $req->mobile_number,
            'address' => $req->address,
            'emergency_person' => $req->emergency_person,
            'emergency_relationship' => $req->emergency_relationship,
            'emergency_number' => $req->emergency_number,
            'emergency_address' => $req->emergency_address,
            'profile_picture' => $newProfilePath,
        ]);
    
        $req->status = 'approved';
        $req->reviewed_at = now();
        $req->reviewed_by = auth()->id();
        $req->save();
    
        return back()->with('success', 'Request approved and changes applied.');
    }



    public function rejectRequest($id)
    {
        $req = StudentEditRequest::findOrFail($id);

        $req->status = 'rejected';
        $req->reviewed_at = now();
        $req->reviewed_by = auth()->id();
        $req->save();

        return back()->with('success', 'Request rejected.');
    }



    public function pendingRequests(Request $request)
    {
        $search = $request->search;

        // Pending only
        $pending = StudentEditRequest::with('student')
            ->where('status', 'pending')
            ->when($search, function ($query) use ($search) {
                $query->whereHas('student', function ($q) use ($search) {
                    $q->where('lastname', 'like', "%$search%")
                        ->orWhere('firstname', 'like', "%$search%");
                });
            })
            ->latest()
            ->paginate(10, ['*'], 'pending_page');

        // Logs (approved + rejected)
        $logs = StudentEditRequest::with('student')
            ->whereIn('status', ['approved', 'rejected'])
            ->when($search, function ($query) use ($search) {
                $query->whereHas('student', function ($q) use ($search) {
                    $q->where('lastname', 'like', "%$search%")
                        ->orWhere('firstname', 'like', "%$search%");
                });
            })
            ->latest()
            ->paginate(10, ['*'], 'logs_page');

        return view('students.pending_requests', compact('pending', 'logs', 'search'));
    }


}
