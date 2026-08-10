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
        ]);
    }

    public function updateScanMessage(Request $request)
    {
        $request->validate([
            'arrival' => 'required|string|max:500',
            'departure' => 'required|string|max:500',
            'morning_in' => 'required|string|max:500',
            'lunch_out' => 'required|string|max:500',
            'afternoon_in' => 'required|string|max:500',
            'eod_out' => 'required|string|max:500',
            'missed_eod' => 'required|string|max:500',
            'consecutive_late' => 'required|string|max:500',
            'consecutive_absent' => 'required|string|max:500',
        ]);

        Setting::setSmsTemplates([
            'arrival' => $request->input('arrival'),
            'departure' => $request->input('departure'),
            'morning_in' => $request->input('morning_in'),
            'lunch_out' => $request->input('lunch_out'),
            'afternoon_in' => $request->input('afternoon_in'),
            'eod_out' => $request->input('eod_out'),
            'missed_eod' => $request->input('missed_eod'),
            'consecutive_late' => $request->input('consecutive_late'),
            'consecutive_absent' => $request->input('consecutive_absent'),
        ]);

        return back()->with('success', 'Gate SMS templates saved.');
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

        // Faculty advisers: always limited to their adviser classes.
        if ($user && $user->role === 'faculty') {
            AdvisoryScope::applyToManageableStudents($query, $user);
        }

        if ($request->year) {
            $query->where('year', $request->year);
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
