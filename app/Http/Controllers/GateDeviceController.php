<?php

namespace App\Http\Controllers;

use App\Models\GateDevice;
use Illuminate\Http\Request;

class GateDeviceController extends Controller
{
    public function index()
    {
        $devices = GateDevice::query()->orderByDesc('id')->get();

        return view('gate_devices.index', compact('devices'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $issued = GateDevice::issue($validated['name']);

        return redirect()
            ->route('gate_devices.index')
            ->with('success', 'Gate device registered.')
            ->with('issued_token', $issued['plain_token'])
            ->with('issued_device_name', $issued['device']->name);
    }

    public function update(Request $request, GateDevice $gateDevice)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'required|boolean',
        ]);

        $gateDevice->update([
            'name' => $validated['name'],
            'is_active' => (bool) $validated['is_active'],
        ]);

        return back()->with('success', 'Gate device updated.');
    }

    public function destroy(GateDevice $gateDevice)
    {
        $gateDevice->delete();

        return back()->with('success', 'Gate device removed.');
    }
}
