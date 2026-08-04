<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class ActivityLogger
{
    /** @var list<string> */
    private const SKIP_ROUTES = [
        'sms.count',
    ];

    /** @var list<string> */
    private const REDACT_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        '_token',
        'remember',
    ];

    public function shouldLog(Request $request, Response $response): bool
    {
        if (in_array($request->method(), ['HEAD', 'OPTIONS'], true)) {
            return false;
        }

        if ($request->isMethod('POST') && $request->is('login', 'logout')) {
            return false;
        }

        $routeName = $request->route()?->getName();
        if ($routeName && in_array($routeName, self::SKIP_ROUTES, true)) {
            return false;
        }

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }

        if ($request->isMethod('GET') && $routeName) {
            return (bool) preg_match(
                '/\.(export|download|pdf|excel|template|bulk|ids|pass)/',
                $routeName
            );
        }

        return false;
    }

    public function logRequest(Request $request, Response $response): ?ActivityLog
    {
        if (! $this->shouldLog($request, $response)) {
            return null;
        }

        $user = $request->user();
        $routeName = $request->route()?->getName() ?? $request->path();
        $action = is_string($routeName) ? $routeName : 'http.request';

        return $this->record(
            action: $action,
            summary: $this->summarize($action, $request),
            method: $request->method(),
            routeName: is_string($routeName) ? $routeName : null,
            url: $request->fullUrl(),
            user: $user,
            ipAddress: $request->ip(),
            userAgent: (string) $request->userAgent(),
            statusCode: $response->getStatusCode(),
            properties: $this->propertiesFromRequest($request),
        );
    }

    /**
     * @param  array<string, mixed>|null  $properties
     */
    public function record(
        string $action,
        string $summary,
        string $method = 'POST',
        ?string $routeName = null,
        ?string $url = null,
        ?User $user = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?int $statusCode = null,
        ?array $properties = null,
    ): ActivityLog {
        return ActivityLog::create([
            'user_id' => $user?->id,
            'user_name' => $user ? trim($user->fname.' '.$user->lname) ?: $user->email : null,
            'user_role' => $user?->role,
            'action' => $action,
            'summary' => $summary,
            'method' => strtoupper($method),
            'route_name' => $routeName,
            'url' => $url ?? '',
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'status_code' => $statusCode,
            'properties' => $properties,
        ]);
    }

    public function logAuthEvent(string $action, string $summary, ?User $user, Request $request, ?array $properties = null): ActivityLog
    {
        return $this->record(
            action: $action,
            summary: $summary,
            method: $request->method(),
            routeName: $request->route()?->getName(),
            url: $request->fullUrl(),
            user: $user,
            ipAddress: $request->ip(),
            userAgent: (string) $request->userAgent(),
            properties: $properties,
        );
    }

    /** @return array<string, mixed> */
    private function propertiesFromRequest(Request $request): array
    {
        $properties = [];

        foreach ($request->route()?->parameters() ?? [] as $key => $value) {
            if (is_object($value) && method_exists($value, 'getKey')) {
                $properties['route_params'][$key] = $value->getKey();
            } elseif (is_scalar($value)) {
                $properties['route_params'][$key] = $value;
            }
        }

        foreach ($request->except(self::REDACT_KEYS) as $key => $value) {
            if ($value instanceof UploadedFile) {
                $properties['input'][$key] = [
                    'filename' => $value->getClientOriginalName(),
                    'size' => $value->getSize(),
                ];

                continue;
            }

            if (is_array($value)) {
                $properties['input'][$key] = $this->truncateValue($this->redactArray($value));
                continue;
            }

            if (is_string($value) && strlen($value) > 500) {
                $properties['input'][$key] = substr($value, 0, 500).'…';
                continue;
            }

            $properties['input'][$key] = $value;
        }

        return $properties;
    }

    /** @param  array<string, mixed>  $data */
    private function redactArray(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACT_KEYS, true)) {
                $redacted[$key] = '[redacted]';
                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redactArray($value) : $value;
        }

        return $redacted;
    }

    private function truncateValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (count($value) > 25) {
            return array_merge(
                array_slice($value, 0, 25, true),
                ['_truncated' => (count($value) - 25).' more item(s)']
            );
        }

        return $value;
    }

    private function summarize(string $action, Request $request): string
    {
        $labels = $this->actionLabels();
        $base = $labels[$action] ?? $this->humanizeAction($action);

        $params = $request->route()?->parameters() ?? [];
        $replacements = [];

        foreach (['id', 'student', 'sf2', 'program', 'course', 'gradeSection', 'schoolStrand', 'visitor'] as $key) {
            if (! isset($params[$key])) {
                continue;
            }

            $value = $params[$key];
            $replacements[':'.$key] = is_object($value) && method_exists($value, 'getKey')
                ? (string) $value->getKey()
                : (string) $value;
        }

        if ($replacements !== []) {
            $base = strtr($base, $replacements);
        }

        if ($action === 'sms.send' && $request->filled('recipient')) {
            $base .= $request->input('recipient') === 'student'
                ? ' (to student mobiles)'
                : ' (to emergency contacts)';
        }

        return $base;
    }

    private function humanizeAction(string $action): string
    {
        return ucfirst(str_replace(['.', '_', '-'], ' ', $action));
    }

    /** @return array<string, string> */
    public function actionLabels(): array
    {
        return [
            'auth.login.success' => 'Logged in',
            'auth.login.failed' => 'Failed login attempt',
            'auth.logout' => 'Logged out',
            'logout' => 'Logged out',
            'students.store' => 'Registered student',
            'students.update' => 'Updated student #:id',
            'students.destroy' => 'Deleted student #:id',
            'students.approve' => 'Approved pending student #:id',
            'students.reject' => 'Rejected pending student #:id',
            'students.import' => 'Imported students from spreadsheet',
            'students.rfid.import' => 'Imported student RFID tags',
            'students.sex.import' => 'Updated student genders from spreadsheet',
            'students.face.store' => 'Enrolled face for student #:student',
            'students.face.destroy' => 'Removed face enrollment for student #:student',
            'employees.store' => 'Created employee',
            'employees.update' => 'Updated employee #:id',
            'employees.destroy' => 'Deleted employee #:id',
            'employees.approve' => 'Approved pending employee #:id',
            'employees.reject' => 'Rejected pending employee #:id',
            'employees.import' => 'Imported employees from spreadsheet',
            'users.store' => 'Created user account',
            'users.update' => 'Updated user account #:id',
            'users.destroy' => 'Deleted user account #:id',
            'pending.store' => 'Public student registration submitted',
            'pendingEmployee.store' => 'Public employee registration submitted',
            'visitors.store' => 'Public visitor registration submitted',
            'visitors.issue.store' => 'Issued visitor pass',
            'attendance.process' => 'Gate QR/RFID scan processed',
            'attendance.face.identify' => 'Gate face scan processed',
            'attendance.section' => 'Gate section selection recorded',
            'attendance.visitor' => 'Visitor gate scan processed',
            'attendance.uploadVideo' => 'Uploaded gate terminal video',
            'attendance.feedback.settings.update' => 'Updated logout feedback setting',
            'attendance.section.settings.update' => 'Updated gate section picker settings',
            'attendance.policy.settings.update' => 'Updated attendance policy (times & SMS thresholds)',
            'attendance.feedback.store' => 'Gate logout feedback submitted',
            'sms.send' => 'Sent SMS blast',
            'sms.scanMessage.update' => 'Updated gate SMS message templates',
            'sf2.store' => 'Created SF2 report',
            'sf2.update' => 'Updated SF2 report #:sf2',
            'sf2.destroy' => 'Deleted SF2 report #:sf2',
            'school-setup.programs.store' => 'Added school program',
            'school-setup.programs.update' => 'Updated school program #:program',
            'school-setup.programs.destroy' => 'Deleted school program #:program',
            'school-setup.courses.store' => 'Added program course',
            'school-setup.courses.update' => 'Updated program course #:course',
            'school-setup.courses.destroy' => 'Deleted program course #:course',
            'school-setup.sections.store' => 'Added grade section',
            'school-setup.sections.destroy' => 'Deleted grade section #:gradeSection',
            'school-setup.strands.store' => 'Added school strand',
            'school-setup.strands.destroy' => 'Deleted school strand #:schoolStrand',
            'files.upload' => 'Uploaded file to repository',
            'files.delete' => 'Deleted file #:id from repository',
            'password.email' => 'Requested password reset email',
            'password.store' => 'Reset account password',
            'students.export' => 'Exported student list',
            'employees.export' => 'Exported employee list',
            'students.bulk.ids' => 'Bulk downloaded student ID cards',
            'employees.bulk.ids' => 'Bulk downloaded employee ID cards',
            'idcard.download' => 'Downloaded student ID card #:id',
            'employees.id.download' => 'Downloaded employee ID card #:id',
            'attendance_logs.export.excel' => 'Exported attendance logs (Excel)',
            'attendance_logs.export.pdf' => 'Exported attendance logs (PDF)',
            'attendance_logs.reports.export' => 'Exported patron attendance report (CSV)',
            'sf2.pdf' => 'Downloaded SF2 report PDF #:sf2',
            'sf2.excel' => 'Downloaded SF2 report Excel #:sf2',
            'students.import.template' => 'Downloaded student import template',
            'students.rfid.template' => 'Downloaded RFID import template',
            'employees.import.template' => 'Downloaded employee import template',
            'visitors.pass' => 'Viewed visitor pass #:visitor',
        ];
    }

    /** @return list<string> */
    public function catalogActions(): array
    {
        return array_keys($this->actionLabels());
    }
}
