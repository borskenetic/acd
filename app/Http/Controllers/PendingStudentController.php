<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PendingStudent;
use App\Models\PendingEmployee;
use App\Models\Role;
use App\Support\PatronOptions;
use App\Support\SchoolSetupOptions;
use App\Support\TableColumns;
use App\Support\AdvisoryScope;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PendingStudentController extends Controller
{
    
    public function index(Request $request)
    {
        $search = $request->input('search');
    
        // Students query
        $studentsQuery = PendingStudent::with('role')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('firstname', 'like', "%{$search}%")
                      ->orWhere('lastname', 'like', "%{$search}%")
                      ->orWhere('course', 'like', "%{$search}%")
                      ->orWhere('year', 'like', "%{$search}%");
                });
            });
        AdvisoryScope::applyToPendingStudents($studentsQuery);
        $pendingStudents = $studentsQuery->paginate(10, ['*'], 'students_page');
    
        // Employees (faculty do not see employee pending)
        $pendingEmployees = collect();
        if (auth()->user()?->can('isAdminOrStaff')) {
            $pendingEmployees = PendingEmployee::with('role')
                ->when($search, function ($query, $search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('firstname', 'like', "%{$search}%")
                          ->orWhere('lastname', 'like', "%{$search}%")
                          ->orWhere('department', 'like', "%{$search}%")
                          ->orWhere('position', 'like', "%{$search}%");
                    });
                })
                ->paginate(10, ['*'], 'employees_page');
        }
    
        return view('pending.index', compact('pendingStudents', 'pendingEmployees', 'search'));
    }


    public function create()
    {
        $roles = Role::all();
        $programs = SchoolSetupOptions::programsForForms();
        $schoolSetup = SchoolSetupOptions::registrationData();

        return view('pending.register', compact('roles', 'programs', 'schoolSetup'));
    }

    public function store(Request $request)
    {
        // Validation
        $validated = $request->validate([
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'middle_initial' => 'nullable|string|max:255',
            'student_id' => 'nullable|string|max:255',
            'mobile_number' => 'nullable|string|max:255',
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
            'birth_date' => 'nullable|date',
            'blood_type' => 'nullable|string|max:5',
            'emergency_person' => 'nullable|string|max:255',
            'emergency_relationship' => 'nullable|string|max:255',
            'emergency_number' => 'nullable|string|max:255',
            'emergency_address' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png|max:4096',
            'student_signature' => 'nullable|string',
        ]);

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
            if (preg_match('/data:image\/(jpeg|jpg)/i', $meta)) $ext = 'jpg';
            $sigName = time() . '_sig.' . $ext;

            if (!file_exists(base_path('images/student_signatures'))) {
                mkdir(base_path('images/student_signatures'), 0755, true);
            }

            file_put_contents(base_path('images/student_signatures/' . $sigName), base64_decode($contents));
            $validated['student_signature'] = 'images/student_signatures/' . $sigName;
        }

        PendingStudent::create(TableColumns::filter('pending_students', $validated));

        return redirect()->back()->with('success', 'Student registration submitted! Await admin approval.');
    }
}
