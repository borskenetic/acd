<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Student;
use App\Support\AdvisoryScope;
use App\Support\PatronOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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
            $yearOptions = PatronOptions::allYearOptions();
            $courses = Student::select('course')
                ->whereNotNull('course')
                ->distinct()
                ->orderBy('course')
                ->pluck('course');
            $sections = Student::query()
                ->whereNotNull('section')
                ->where('section', '!=', '')
                ->distinct()
                ->orderBy('section')
                ->pluck('section');
            $sectionsByGrade = Student::query()
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
            'canManageSimLoad' => $user && in_array($user->role, ['admin', 'staff'], true),
        ]);
    }

    public function updateSimLoad(Request $request)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'staff'], true)) {
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

    public function send(Request $request)
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

        $payload = [];

        foreach ($students as $student) {
            $name = $student->firstname.' '.$student->lastname;
            $message = str_replace('{name}', $name, $request->message);
            $number = $this->normalizePhilippineMobile((string) $student->{$column});

            if ($number === '') {
                continue;
            }

            $payload[] = [
                'number' => $number,
                'message' => $message,
            ];
        }

        if ($payload === []) {
            return back()->with('error', 'No recipients found with a valid number for the selected filters.');
        }

        // send to your local Python server
        $python_server = "https://cloakedly-ineffective-amara.ngrok-free.dev/send-sms"; // your ngrok URL
        $api_key = "library123"; // must match Python server

        Http::withHeaders([
            'X-API-KEY' => $api_key,
        ])->timeout(300)
            ->post($python_server, $payload);

        $label = $request->input('recipient') === 'student' ? 'student mobile numbers' : 'emergency contacts';

        return back()->with('success', 'SMS sent to '.count($payload).' '.$label.'.');
    }

    public function sendDirect(string $number, string $message): bool
    {
        $number = $this->normalizePhilippineMobile($number);

        if ($number === '') {
            return false;
        }

        $url = config('services.sms_modem.url', env('SMS_MODEM_URL'));
        $apiKey = config('services.sms_modem.key', env('SMS_MODEM_API_KEY'));

        if (! $url) {
            return false;
        }

        try {
            $response = Http::withHeaders(['X-API-KEY' => $apiKey])
                ->timeout(30)
                ->post($url, [
                    ['number' => $number, 'message' => $message],
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private function normalizePhilippineMobile(string $number): string
    {
        $number = preg_replace('/\s+/', '', $number);

        if (str_starts_with($number, '0')) {
            return '+63'.substr($number, 1);
        }

        if (str_starts_with($number, '63')) {
            return '+'.$number;
        }

        return $number;
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
