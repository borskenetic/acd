<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Support\Facades\Http;

class SmsController extends Controller
{

    public function index()
    {
        $courses = \App\Models\Student::select('course')
            ->whereNotNull('course')
            ->distinct()
            ->orderBy('course')
            ->pluck('course');
    
        return view('sms.blast', [
            'courses' => $courses
        ]);
    }

    public function scanMessage()
    {
        return view('sms.scan_message', [
            'arrival' => Setting::scanSmsArrivalTemplate(),
            'departure' => Setting::scanSmsDepartureTemplate(),
            'consecutiveLate' => Setting::smsConsecutiveLateTemplate(),
            'consecutiveAbsent' => Setting::smsConsecutiveAbsentTemplate(),
        ]);
    }

    public function updateScanMessage(Request $request)
    {
        $request->validate([
            'arrival' => 'required|string|max:500',
            'departure' => 'required|string|max:500',
            'consecutive_late' => 'required|string|max:500',
            'consecutive_absent' => 'required|string|max:500',
        ]);

        Setting::setSmsTemplates([
            'arrival' => $request->input('arrival'),
            'departure' => $request->input('departure'),
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

        $query = Student::query()
            ->whereNotNull($column)
            ->where($column, '!=', '');

        if ($request->year) {
            $query->where('year', $request->year);
        }

        if ($request->course) {
            $query->where('course', $request->course);
        }

        return $query;
    }
}