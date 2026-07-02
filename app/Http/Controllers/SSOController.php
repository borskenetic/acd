<?php

namespace App\Http\Controllers;

use App\Services\ZendyTrackingService;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;

class SSOController extends Controller
{
    public function __construct(private ZendyTrackingService $tracking) {}

    public function redirectToLibrary(Request $request)
    {
        $user = auth()->user();

        if (! config('zendy.sso_enabled')) {
            $this->tracking->logAccess($request, 'zendy_sso_unavailable', $user, [
                'fallback' => 'direct_redirect',
                'reason' => 'SSO not enabled — using direct Zendy link',
            ]);

            return redirect()->route('zendy.launch');
        }

        $this->tracking->logAccess($request, 'zendy_sso', $user);

        $displayName = trim(implode(' ', array_filter([$user->fname ?? '', $user->lname ?? ''])));
        if ($displayName === '') {
            $displayName = $user->email;
        }

        $payload = [
            'sub' => $user->id,
            'email' => $user->email,
            'name' => $displayName,
            'iat' => time(),
            'exp' => time() + 300,
        ];

        $token = JWT::encode($payload, env('SSO_SECRET'), 'HS256');
        $ssoUrl = rtrim(config('zendy.sso_url'), '?').'?token='.urlencode($token);

        return redirect()->away($ssoUrl);
    }
}
