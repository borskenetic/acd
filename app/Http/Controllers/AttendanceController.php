<?php

namespace App\Http\Controllers;

use App\Models\GateDevice;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Visitor;
use App\Models\VisitorLog;
use App\Services\AttendanceSessionService;
use App\Services\AttendancePolicyService;
use App\Services\FaceMatchService;
use App\Services\StudentDeparturePolicy;
use App\Services\StudentScanService;
use App\Support\ScanConfirmToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function __construct(
        protected StudentScanService $studentScan,
    ) {}
    public function showScanner()
    {
        return view('attendance.scan', $this->scannerViewData());
    }

    public function showFaceScanner(FaceMatchService $faces)
    {
        if (! config('face.enabled')) {
            abort(404);
        }

        return view('attendance.face_scan', array_merge($this->scannerViewData(), [
            'faceEnrolledCount' => $faces->enrolledCount(),
            'faceModelCdn' => config('face.model_cdn'),
        ]));
    }

    protected function effectiveLogoutFeedbackEnabled(): bool
    {
        return $this->studentScan->logoutFeedbackEnabled();
    }

    protected function effectiveSectionPickerEnabled(): bool
    {
        return $this->studentScan->sectionPickerEnabled();
    }

    /** @return array<string, mixed> */
    protected function scannerViewData(): array
    {
        $departure = app(StudentDeparturePolicy::class);

        return [
            'logoutFeedbackEnabled' => $this->effectiveLogoutFeedbackEnabled(),
            'sectionPickerEnabled' => $this->effectiveSectionPickerEnabled(),
            'attendanceSections' => Setting::attendanceSections(),
            'earlyDepartureEnabled' => $departure->isEnabled(),
            'earlyDepartureCutoffLabel' => $departure->earliestOutLabel(),
        ];
    }

    public function feedbackSettings()
    {
        if (! config('attendance.logout_feedback_enabled')) {
            abort(404);
        }

        return view('attendance.feedback_settings', [
            'enabled' => Setting::logoutFeedbackEnabled(),
        ]);
    }

    public function updateFeedbackSettings(Request $request)
    {
        if (! config('attendance.logout_feedback_enabled')) {
            abort(404);
        }

        $request->validate([
            'enabled' => 'required|in:0,1',
        ]);

        Setting::setLogoutFeedbackEnabled($request->input('enabled') === '1');

        return back()->with(
            'success',
            $request->input('enabled') === '1'
                ? 'Logout feedback is now enabled on the gate terminal.'
                : 'Logout feedback is now disabled on the gate terminal.'
        );
    }

    public function sectionSettings()
    {
        if (! config('attendance.section_picker_enabled')) {
            abort(404);
        }

        return view('attendance.section_settings', [
            'enabled' => Setting::sectionPickerEnabled(),
            'sections' => Setting::attendanceSections(),
        ]);
    }

    public function updateSectionSettings(Request $request)
    {
        if (! config('attendance.section_picker_enabled')) {
            abort(404);
        }

        $request->validate([
            'enabled' => 'required|in:0,1',
            'sections' => 'required|array|min:1',
            'sections.*' => 'required|string|max:120|distinct',
        ]);

        $sections = array_values(array_unique(array_filter(array_map(
            fn ($name) => trim((string) $name),
            $request->input('sections', [])
        ))));

        Setting::setSectionPickerEnabled($request->input('enabled') === '1');
        Setting::setAttendanceSections($sections);

        $pickerOn = $request->input('enabled') === '1';

        return back()->with(
            'success',
            $pickerOn
                ? 'Section picker enabled with '.count($sections).' section(s) on the gate terminal.'
                : 'Section picker disabled. '.count($sections).' section(s) saved for logs and filters.'
        );
    }

    public function policySettings(AttendancePolicyService $policy)
    {
        $user = auth()->user();

        return view('attendance.policy_settings', [
            'policy' => $policy,
            'values' => $policy->toFormValues(),
            'canEditK10' => $policy->canEditK10Schedule($user),
            'canEditShs' => $policy->canEditShsSchedule($user),
            'canEditShared' => $policy->canEditSharedPolicy($user),
            'scopeLabel' => $user?->isBandAdmin()
                ? ($user->role === 'shs_admin' ? 'SHS Admin — Grade 11–12 schedules only' : 'K–10 Admin — Kinder–Grade 10 schedules only')
                : null,
        ]);
    }

    public function updatePolicySettings(Request $request, AttendancePolicyService $policy)
    {
        $user = $request->user();
        $canK10 = $policy->canEditK10Schedule($user);
        $canShs = $policy->canEditShsSchedule($user);
        $canShared = $policy->canEditSharedPolicy($user);

        $rules = [];
        if ($canK10) {
            $rules['login_time'] = 'required|date_format:H:i';
            $rules['logout_time'] = 'required|date_format:H:i';
        }
        if ($canShs) {
            $rules['shs_login_time'] = 'required|date_format:H:i';
            $rules['shs_logout_time'] = 'required|date_format:H:i';
            $rules['shs_evening_login_time'] = 'required|date_format:H:i';
            $rules['shs_evening_logout_time'] = 'required|date_format:H:i';
        }
        if ($canShared) {
            $rules['tardy_grace_minutes'] = 'required|integer|min:0|max:120';
            $rules['consecutive_late_threshold'] = 'required|integer|min:1|max:30';
            $rules['consecutive_absent_threshold'] = 'required|integer|min:1|max:30';
            $rules['temp_enabled'] = 'nullable|boolean';
            $rules['temp_login_time'] = 'nullable|date_format:H:i';
            $rules['temp_logout_time'] = 'nullable|date_format:H:i';
            $rules['temp_starts_on'] = 'nullable|date';
            $rules['temp_ends_on'] = 'nullable|date|after_or_equal:temp_starts_on';
            $rules['temp_apply_to_default'] = 'nullable|boolean';
            $rules['temp_apply_to_shs'] = 'nullable|boolean';
            $rules['temp_apply_to_shs_evening'] = 'nullable|boolean';
        } elseif ($canK10 || $canShs) {
            // Band admins may set a temporary override only for their band flags.
            $rules['temp_enabled'] = 'nullable|boolean';
            $rules['temp_login_time'] = 'nullable|date_format:H:i';
            $rules['temp_logout_time'] = 'nullable|date_format:H:i';
            $rules['temp_starts_on'] = 'nullable|date';
            $rules['temp_ends_on'] = 'nullable|date|after_or_equal:temp_starts_on';
            if ($canK10) {
                $rules['temp_apply_to_default'] = 'nullable|boolean';
            }
            if ($canShs) {
                $rules['temp_apply_to_shs'] = 'nullable|boolean';
                $rules['temp_apply_to_shs_evening'] = 'nullable|boolean';
            }
        }

        $request->validate($rules);

        $policy->save($request->all(), $user);

        return back()->with('success', 'Attendance policy saved. Gate logs, SF2, and SMS alerts will use the new times and thresholds.');
    }

    /**
     * Confirm this browser is paired as a named kiosk (token in header/body).
     */
    public function kioskStatus(Request $request)
    {
        $device = GateDevice::resolveFromRequest($request);

        if (! $device) {
            return response()->json([
                'paired' => false,
                'message' => 'No active kiosk for this token.',
            ], 404);
        }

        return response()->json([
            'paired' => true,
            'id' => $device->id,
            'name' => $device->name,
        ]);
    }

    public function scan(Request $request)
    {
        $request->validate(['qrcode' => 'required|string']);

        // Keep last-seen fresh when the paired kiosk is actively scanning.
        GateDevice::resolveFromRequest($request);

        $student = $this->studentScan->resolveStudent($request->qrcode);

        if ($student) {
            return response()->json($this->studentScan->previewScan($student));
        }

        $visitor = $this->resolveVisitor($request->qrcode);

        if ($visitor) {
            return response()->json($this->buildVisitorScanResponse($visitor));
        }

        return response()->json([
            'type' => 'error',
            'message' => 'ID not recognized. Students and employees use their school ID. Visitors must register first.',
        ]);
    }

    public function identifyByFace(Request $request, FaceMatchService $faces)
    {
        if (! config('face.enabled')) {
            abort(404);
        }

        $request->validate([
            'descriptor' => 'required|array|size:'.config('face.descriptor_length', 128),
            'descriptor.*' => 'numeric',
        ]);

        $match = $faces->findBestMatch($request->input('descriptor'));

        // Keep last-seen on the paired named kiosk.
        GateDevice::resolveFromRequest($request);

        if ($match === null) {
            return response()->json([
                'type' => 'error',
                'message' => 'Face not recognized. Please enroll or try again.',
            ]);
        }

        return response()->json($this->studentScan->previewScan($match['student']));
    }

    public function processSection(Request $request)
    {
        $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'section' => 'nullable|string|max:255',
            'confirm_token' => 'required|string',
        ]);

        try {
            ScanConfirmToken::assertValid(
                (string) $request->input('confirm_token'),
                'student',
                (int) $request->input('student_id')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $section = $request->section ? trim((string) $request->section) : null;
        $student = Student::findOrFail($request->student_id);

        $gateDevice = GateDevice::resolveFromRequest($request);
        if (config('attendance.require_kiosk_token') && ! $gateDevice) {
            return response()->json([
                'message' => 'This kiosk is not paired. Pair a gate device token before recording attendance.',
            ], 403);
        }

        try {
            $result = $this->studentScan->recordScan(
                $student,
                $section,
                gateDevice: $gateDevice,
                source: $gateDevice ? 'web_kiosk' : 'web',
            );
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $status = str_contains(strtolower($message), 'already scanned') ? 409 : 403;

            return response()->json([
                'message' => $message,
                'allowed_after' => $status === 403
                    ? app(StudentDeparturePolicy::class)->earliestOutLabel()
                    : null,
            ], $status);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => $result['status'],
            'scanned_at' => $result['scanned_at'],
            'logout_feedback_enabled' => $this->studentScan->logoutFeedbackEnabled(),
        ]);
    }

    public function processVisitor(Request $request)
    {
        $request->validate([
            'visitor_id' => 'required|integer|exists:visitors,id',
            'confirm_token' => 'required|string',
        ]);

        try {
            ScanConfirmToken::assertValid(
                (string) $request->input('confirm_token'),
                'visitor',
                (int) $request->input('visitor_id')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $gateDevice = GateDevice::resolveFromRequest($request);
        if (config('attendance.require_kiosk_token') && ! $gateDevice) {
            return response()->json([
                'message' => 'This kiosk is not paired. Pair a gate device token before recording attendance.',
            ], 403);
        }

        $visitor = Visitor::findOrFail($request->visitor_id);

        try {
            $payload = DB::transaction(function () use ($visitor) {
                Visitor::query()->whereKey($visitor->id)->lockForUpdate()->first();

                $sessions = app(AttendanceSessionService::class);
                $sessions->closeStaleOpenInForVisitor($visitor);

                $lastLog = VisitorLog::where('visitor_id', $visitor->id)
                    ->orderByDesc('scanned_at')
                    ->orderByDesc('id')
                    ->first();

                $newStatus = ($lastLog && $sessions->isInStatus($lastLog->status)) ? 'OUT' : 'IN';

                $log = VisitorLog::create([
                    'visitor_id' => $visitor->id,
                    'status' => $newStatus,
                    'scanned_at' => now(),
                ]);

                return [
                    'status' => $newStatus,
                    'scanned_at' => $log->scanned_at->format('Y-m-d h:i:s A'),
                ];
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not record visitor scan.'], 500);
        }

        return response()->json($payload);
    }

    /** @return array<string, mixed> */
    protected function buildVisitorScanResponse(Visitor $visitor): array
    {
        app(AttendanceSessionService::class)->closeStaleOpenInForVisitor($visitor);

        $sessions = app(AttendanceSessionService::class);
        $lastLog = VisitorLog::where('visitor_id', $visitor->id)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();

        $nextStatus = ($lastLog && $sessions->isInStatus($lastLog->status)) ? 'OUT' : 'IN';

        return [
            'type' => 'visitor',
            'next_status' => $nextStatus,
            'visitor_id' => $visitor->id,
            'confirm_token' => ScanConfirmToken::issue('visitor', (int) $visitor->id),
            'visitor' => [
                'id' => $visitor->id,
                'firstname' => $visitor->firstname,
                'lastname' => $visitor->lastname,
                'organization' => $visitor->organization,
            ],
        ];
    }

    private function resolveVisitor(string $raw): ?Visitor
    {
        $token = trim(str_replace("\r", '', $raw));

        if ($token === '') {
            return null;
        }

        return Visitor::where('qrcode', $token)->first();
    }

    public function showChangeVideo()
    {
        return view('attendance.change_video');
    }

    public function uploadVideo(Request $request)
    {
        $request->validate([
            'video' => 'required|file|mimes:mp4|max:512000',
        ]);

        $video = $request->file('video');
        $filename = 'area51_product_slideshow.mp4';
        $video->move(base_path('videos'), $filename);

        return redirect()->route('attendance.changeVideo')->with('success', 'Video uploaded successfully!');
    }
}

