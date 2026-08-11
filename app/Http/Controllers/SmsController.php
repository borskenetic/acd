<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Student;
use App\Services\ModemSmsService;
use App\Support\AdvisoryScope;
use App\Support\PatronOptions;
use Illuminate\Http\Request;

class SmsController extends Controller
{

    public function index()
    {
        $user = auth()->user();
        $facultyLocked = $user && $user->role === 'faculty';

        if ($facultyLocked) {
            $yearOptions = AdvisoryScope::yearOptions($user);
            $sectionsByGrade = AdvisoryScope::sectionsByYear($user);
            $sections = collect($sectionsByGrade)->flatten()->unique()->sort()->values();
            $courses = Student::query()
                ->tap(fn ($q) => AdvisoryScope::applyToManageableStudents($q, $user))
                ->whereNotNull('course')
                ->where('course', '!=', '')
                ->distinct()
                ->orderBy('course')
                ->pluck('course');
        } else {
            $yearOptions = AdvisoryScope::yearOptions($user);
            $studentQuery = Student::query()->tap(fn ($q) => AdvisoryScope::applyToStudents($q, $user));
            $courses = (clone $studentQuery)
                ->whereNotNull('course')
                ->where('course', '!=', '')
                ->distinct()
                ->orderBy('course')
                ->pluck('course');
            $sections = (clone $studentQuery)
                ->whereNotNull('section')
                ->where('section', '!=', '')
                ->distinct()
                ->orderBy('section')
                ->pluck('section');
            $sectionsByGrade = (clone $studentQuery)
                ->whereNotNull('section')
                ->where('section', '!=', '')
                ->whereNotNull('year')
                ->where('year', '!=', '')
                ->get(['year', 'section'])
                ->groupBy('year')
                ->map(fn ($rows) => $rows->pluck('section')->unique()->sort()->values()->all())
                ->all();
        }

        return view('sms.blast', [
            'courses' => $courses,
            'yearOptions' => $yearOptions,
            'sections' => $sections,
            'sectionsByGrade' => $sectionsByGrade,
            'facultyLocked' => $facultyLocked,
            'facultyClasses' => $facultyLocked ? AdvisoryScope::managePairs($user) : [],
            'simLoad' => Setting::smsSimLoadStatus(),
            'canManageSimLoad' => $user && $user->isSchoolOps(),
        ]);
    }

    public function updateSimLoad(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->isSchoolOps()) {
            abort(403, 'Only admin or staff can update SIM load.');
        }

        $validated = $request->validate([
            'loaded_on' => 'required|date',
            'days' => 'required|integer|min:1|max:365',
            'warn_days' => 'nullable|integer|min:1|max:30',
        ]);

        Setting::setSmsSimLoad(
            $validated['loaded_on'],
            (int) $validated['days'],
            (int) ($validated['warn_days'] ?? 3),
        );

        return back()->with('success', 'SIM load saved. You’ll see a warning here when it’s about to expire.');
    }

    public function scanMessage()
    {
        $user = auth()->user();
        $scope = AdvisoryScope::gateSmsEditScope($user);

        if (! $scope['k10'] && ! $scope['shs'] && ! $scope['alerts']) {
            abort(403, 'You cannot manage gate SMS templates.');
        }

        $scopeLabel = null;
        if ($user && $user->isBandAdmin()) {
            $scopeLabel = $user->role === 'shs_admin'
                ? 'SHS Admin — Grade 11–12 arrival/departure templates only'
                : 'K–10 Admin — Kinder–Grade 10 session templates only';
        }

        return view('sms.scan_message', [
            'arrival' => Setting::scanSmsArrivalTemplate(),
            'departure' => Setting::scanSmsDepartureTemplate(),
            'morningIn' => Setting::scanSmsMorningInTemplate(),
            'lunchOut' => Setting::scanSmsLunchOutTemplate(),
            'afternoonIn' => Setting::scanSmsAfternoonInTemplate(),
            'eodOut' => Setting::scanSmsEodOutTemplate(),
            'missedEod' => Setting::scanSmsMissedEodTemplate(),
            'consecutiveLate' => Setting::smsConsecutiveLateTemplate(),
            'consecutiveAbsent' => Setting::smsConsecutiveAbsentTemplate(),
            'canEditK10' => $scope['k10'],
            'canEditShs' => $scope['shs'],
            'canEditAlerts' => $scope['alerts'],
            'scopeLabel' => $scopeLabel,
        ]);
    }

    public function updateScanMessage(Request $request)
    {
        $scope = AdvisoryScope::gateSmsEditScope($request->user());
        if (! $scope['k10'] && ! $scope['shs'] && ! $scope['alerts']) {
            abort(403, 'You cannot manage gate SMS templates.');
        }

        $rules = [];
        if ($scope['k10']) {
            $rules['morning_in'] = 'required|string|max:500';
            $rules['lunch_out'] = 'required|string|max:500';
            $rules['afternoon_in'] = 'required|string|max:500';
            $rules['eod_out'] = 'required|string|max:500';
            $rules['missed_eod'] = 'required|string|max:500';
        }
        if ($scope['shs']) {
            $rules['arrival'] = 'required|string|max:500';
            $rules['departure'] = 'required|string|max:500';
        }
        if ($scope['alerts']) {
            $rules['consecutive_late'] = 'required|string|max:500';
            $rules['consecutive_absent'] = 'required|string|max:500';
        }

        $request->validate($rules);

        $payload = [];
        if ($scope['k10']) {
            $payload['morning_in'] = $request->input('morning_in');
            $payload['lunch_out'] = $request->input('lunch_out');
            $payload['afternoon_in'] = $request->input('afternoon_in');
            $payload['eod_out'] = $request->input('eod_out');
            $payload['missed_eod'] = $request->input('missed_eod');
        }
        if ($scope['shs']) {
            $payload['arrival'] = $request->input('arrival');
            $payload['departure'] = $request->input('departure');
        }
        if ($scope['alerts']) {
            $payload['consecutive_late'] = $request->input('consecutive_late');
            $payload['consecutive_absent'] = $request->input('consecutive_absent');
        }

        Setting::setSmsTemplates($payload);

        return back()->with('success', 'Gate SMS templates saved for your access level.');
    }

    public function count(Request $request)
    {
        $request->validate([
            'recipient' => 'nullable|in:student,emergency_contact',
        ]);

        return response()->json([
            'count' => $this->blastQuery($request)->count(),
        ]);
    }

    public function send(Request $request, ModemSmsService $modem)
    {
        $request->validate([
            'message' => 'required|string',
            'recipient' => 'required|in:student,emergency_contact',
            'year' => 'nullable|string',
            'course' => 'nullable|string',
            'section' => 'nullable|string',
        ]);

        $column = $this->recipientColumn($request->input('recipient'));
        $students = $this->blastQuery($request)->get();
        $items = [];

        foreach ($students as $student) {
            $name = trim($student->firstname.' '.$student->lastname);
            $message = str_replace('{name}', $name, $request->message);
            $rawNumber = (string) $student->{$column};
            $number = $modem->normalizePhilippineMobile($rawNumber);

            if ($number === '') {
                continue;
            }

            $items[] = [
                'number' => $number,
                'message' => $message,
                'student_id' => $student->id,
                'recipient_label' => $name,
            ];
        }

        if ($items === []) {
            return back()->with('error', 'No recipients found with a valid number for the selected filters.');
        }

        $result = $modem->sendBatch($items, [
            'type' => 'blast',
            'user_id' => $request->user()?->id,
            'meta' => [
                'recipient' => $request->input('recipient'),
                'year' => $request->input('year'),
                'course' => $request->input('course'),
                'section' => $request->input('section'),
            ],
        ]);

        $label = $request->input('recipient') === 'student' ? 'student mobile numbers' : 'emergency contacts';

        if ($result['sent'] > 0 && $result['failed'] === 0) {
            return back()->with('success', 'SMS sent to '.$result['sent'].' '.$label.'.');
        }

        if ($result['sent'] > 0) {
            return back()->with('success', 'SMS sent to '.$result['sent'].' of '.count($items).' '.$label.'. '.$result['failed'].' failed — check SMS Logs.');
        }

        return back()->with('error', 'SMS failed for all '.count($items).' recipients. Check SMS Logs and the modem connection.');
    }

    /**
     * @param  array{
     *   type?: string,
     *   student_id?: int|null,
     *   user_id?: int|null,
     *   recipient_label?: string|null,
     *   meta?: array<string, mixed>|null
     * }  $context
     */
    public function sendDirect(string $number, string $message, array $context = []): bool
    {
        return app(ModemSmsService::class)->send($number, $message, $context);
    }

    private function recipientColumn(string $recipient): string
    {
        return $recipient === 'student' ? 'mobile_number' : 'emergency_number';
    }

    private function blastQuery(Request $request)
    {
        $recipient = $request->input('recipient', 'emergency_contact');
        $column = $this->recipientColumn($recipient);
        $user = $request->user() ?? auth()->user();

        $query = Student::query()
            ->whereNotNull($column)
            ->where($column, '!=', '');

        // Faculty: adviser classes only. Band admins: grade band. Super/staff: full school then filters.
        if ($user) {
            if ($user->role === 'faculty') {
                AdvisoryScope::applyToManageableStudents($query, $user);
            } elseif ($user->isBandAdmin()) {
                AdvisoryScope::applyToStudents($query, $user);
            }
        }

        if ($request->filled('year')) {
            $year = (string) $request->input('year');
            if ($user && $user->isSchoolOps() && ! $user->canAccessGradeLevel($year)) {
                $query->whereRaw('1 = 0');
            } elseif ($user && $user->role === 'faculty' && ! in_array($year, AdvisoryScope::yearOptions($user), true)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('year', $year);
            }
        }

        if ($request->course) {
            $query->where('course', $request->course);
        }

        if ($request->filled('section')) {
            $query->where('section', $request->section);
        }

        return $query;
    }
}
