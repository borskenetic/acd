<?php

namespace App\Http\Middleware;

use App\Models\GateDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateGateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Gate device token required.'], 401);
        }

        $device = GateDevice::findByToken($token);

        if (! $device) {
            return response()->json(['message' => 'Invalid or inactive gate device token.'], 401);
        }

        $device->touchSeen();
        $request->attributes->set('gate_device', $device);

        return $next($request);
    }
}
