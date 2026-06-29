<?php

namespace App\Http\Controllers;

use App\Models\Visitor;
use App\Support\QrCodePng;
use Illuminate\Http\Request;

class VisitorAdminController extends Controller
{
    public function create()
    {
        return view('visitors.issue');
    }

    public function defaultPass()
    {
        $visitor = Visitor::defaultGuardPass();
        $qrBase64 = QrCodePng::toBase64($visitor->qrcode, 280, 2);

        return view('visitors.pass', [
            'visitor' => $visitor,
            'qrBase64' => $qrBase64,
            'isDefaultGuardPass' => true,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'organization' => 'nullable|string|max:255',
            'purpose' => 'nullable|string|max:255',
        ]);

        $validated['qrcode'] = Visitor::allocateQrCode();
        $visitor = Visitor::create($validated);

        return redirect()->route('visitors.pass', $visitor)
            ->with('success', 'Walk-in visitor pass created. Print or show this QR at the gate.');
    }
}
