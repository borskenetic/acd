<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\UserAdvisory;
use App\Models\GradeSection;
use App\Support\PatronOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function create()
    {
        return view('view_accounts.create', [
            'yearOptions' => PatronOptions::allYearOptions(),
            'sectionsByGrade' => $this->sectionsByGrade(),
            'accessLevelOptions' => UserAdvisory::accessLevelOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'lname' => 'required|string|max:255',
            'fname' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'role' => 'required|in:admin,staff,faculty',
            'advisories' => 'nullable|array',
            'advisories.*.year' => 'nullable|string|max:64',
            'advisories.*.section' => 'nullable|string|max:64',
            'advisories.*.access_level' => 'nullable|in:adviser,subject_teacher',
        ]);

        if ($validated['role'] === 'faculty') {
            $pairs = $this->normalizeAdvisories($request->input('advisories', []));
            if ($pairs === []) {
                throw ValidationException::withMessages([
                    'advisories' => 'Faculty accounts need at least one grade & section assignment.',
                ]);
            }
        } else {
            $pairs = [];
        }

        $user = DB::transaction(function () use ($validated, $pairs) {
            $first = $pairs[0] ?? null;
            $user = User::create([
                'lname' => $validated['lname'],
                'fname' => $validated['fname'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                // Keep legacy columns in sync for any external tooling.
                'advisory_year' => $first['year'] ?? null,
                'advisory_section' => $first['section'] ?? null,
            ]);

            $this->syncAdvisories($user, $pairs);

            return $user;
        });

        return redirect()->route('users.create')->with('success', 'User account created successfully!');
    }

    public function index(Request $request)
    {
        $query = User::query()->with('advisories');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('fname', 'like', "%{$search}%")
                    ->orWhere('lname', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('role', 'like', "%{$search}%")
                    ->orWhere('advisory_year', 'like', "%{$search}%")
                    ->orWhere('advisory_section', 'like', "%{$search}%")
                    ->orWhereHas('advisories', function ($a) use ($search) {
                        $a->where('year', 'like', "%{$search}%")
                            ->orWhere('section', 'like', "%{$search}%");
                    });
            });
        }

        $users = $query->orderBy('lname')->orderBy('fname')->paginate(15)->withQueryString();

        return view('view_accounts.list', compact('users'));
    }

    public function edit($id)
    {
        $user = User::with('advisories')->findOrFail($id);

        return view('view_accounts.edit', [
            'user' => $user,
            'yearOptions' => PatronOptions::allYearOptions(),
            'sectionsByGrade' => $this->sectionsByGrade(),
            'accessLevelOptions' => UserAdvisory::accessLevelOptions(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'fname' => 'required|string',
            'lname' => 'required|string',
            'email' => 'required|email',
            'role' => 'required|in:admin,staff,faculty,student',
            'advisories' => 'nullable|array',
            'advisories.*.year' => 'nullable|string|max:64',
            'advisories.*.section' => 'nullable|string|max:64',
            'advisories.*.access_level' => 'nullable|in:adviser,subject_teacher',
        ]);

        $user = User::findOrFail($id);
        $pairs = $request->input('role') === 'faculty'
            ? $this->normalizeAdvisories($request->input('advisories', []))
            : [];

        if ($request->input('role') === 'faculty' && $pairs === []) {
            throw ValidationException::withMessages([
                'advisories' => 'Faculty accounts need at least one grade & section assignment.',
            ]);
        }

        DB::transaction(function () use ($request, $user, $pairs) {
            $first = $pairs[0] ?? null;
            $user->update([
                'fname' => $request->input('fname'),
                'lname' => $request->input('lname'),
                'email' => $request->input('email'),
                'role' => $request->input('role'),
                'advisory_year' => $first['year'] ?? null,
                'advisory_section' => $first['section'] ?? null,
            ]);
            $this->syncAdvisories($user, $pairs);
        });

        return redirect()->route('users.index')->with('success', 'User updated successfully!');
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted successfully!');
    }

    /**
     * @param  list<array<string, mixed>>|mixed  $raw
     * @return list<array{year: string, section: string, access_level: string}>
     */
    private function normalizeAdvisories(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $year = trim((string) ($row['year'] ?? ''));
            $section = trim((string) ($row['section'] ?? ''));
            if ($year === '' || $section === '') {
                continue;
            }
            $level = (string) ($row['access_level'] ?? UserAdvisory::LEVEL_ADVISER);
            if (! in_array($level, [UserAdvisory::LEVEL_ADVISER, UserAdvisory::LEVEL_SUBJECT], true)) {
                $level = UserAdvisory::LEVEL_ADVISER;
            }
            $key = mb_strtolower($year.'|'.$section);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'year' => $year,
                'section' => $section,
                'access_level' => $level,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{year: string, section: string, access_level: string}>  $pairs
     */
    private function syncAdvisories(User $user, array $pairs): void
    {
        if (! Schema::hasTable('user_advisories')) {
            return;
        }

        $user->advisories()->delete();
        foreach ($pairs as $pair) {
            $user->advisories()->create($pair);
        }
    }

    /** @return array<string, list<string>> */
    private function sectionsByGrade(): array
    {
        $map = [];
        foreach (PatronOptions::allYearOptions() as $year) {
            $map[$year] = [];
        }

        if (Schema::hasTable('grade_sections')) {
            $rows = GradeSection::query()->orderBy('section')->get(['grade_level', 'section']);
            foreach ($rows as $row) {
                $grade = (string) $row->grade_level;
                $section = (string) $row->section;
                if ($grade === '' || $section === '') {
                    continue;
                }
                $map[$grade] ??= [];
                if (! in_array($section, $map[$grade], true)) {
                    $map[$grade][] = $section;
                }
            }
        }

        return $map;
    }
}
