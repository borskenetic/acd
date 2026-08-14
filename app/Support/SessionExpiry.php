<?php

namespace App\Support;

use Illuminate\Http\Request;

class SessionExpiry
{
    public static function guestRedirect(Request $request): string
    {
        if ($request->is('zendy') || $request->is('zendy/*')) {
            return route('zendy.login');
        }

        if (self::looksExpired($request)) {
            return route('session.expired');
        }

        return route('login');
    }

    public static function looksExpired(Request $request): bool
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return true;
        }

        $referer = $request->headers->get('referer');
        if (! is_string($referer) || $referer === '') {
            return false;
        }

        $parts = parse_url($referer);
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';

        if ($host !== $request->getHost()) {
            return false;
        }

        foreach (['/login', '/session-expired', '/register', '/forgot-password', '/reset-password'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        return true;
    }
}
