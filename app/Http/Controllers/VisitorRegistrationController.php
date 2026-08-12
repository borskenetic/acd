<?php

namespace App\Http\Controllers;

use App\Models\Visitor;
use App\Support\QrCodePng;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class VisitorRegistrationController extends Controller
{
    public function create()
    {
        return view('visitors.register');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'organization' => 'nullable|string|max:255',
            'purpose' => 'nullable|string|max:255',
            'mobile_number' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
        ]);

        $validated['qrcode'] = Visitor::allocateQrCode();

        $visitor = Visitor::create($validated);

        return redirect()->to(
            URL::temporarySignedRoute('visitors.pass', now()->addDays(14), ['visitor' => $visitor])
        );
    }

    public function pass(Request $request, Visitor $visitor)
    {
        // Signed public link, or any logged-in school ops / faculty user.
        if (! $request->hasValidSignature() && ! auth()->check()) {
            abort(403, 'This visitor pass link is invalid or has expired. Please register again or ask the guardhouse for a new pass.');
        }

        $qrBase64 = QrCodePng::toBase64($visitor->qrcode, 280, 2);

        return view('visitors.pass', compact('visitor', 'qrBase64'));
    }
}
