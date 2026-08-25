<?php

namespace App\Http\Controllers;

use App\Models\GateDevice;
use Illuminate\Http\Request;

class GateDeviceController extends Controller
{
    public function index()
    {
        $this->authorizeGateDeviceManage();

        $devices = GateDevice::query()->orderByDesc('id')->get();

        // Temporary display-only: Gate B - Kiosk 2 mirrors Gate B - Kiosk 1 timestamps.
        $source = $devices->firstWhere('name', 'Gate B - Kiosk 1');
        $target = $devices->firstWhere('name', 'Gate B - Kiosk 2');
        if ($source && $target) {
            $target->last_seen_at = $source->last_seen_at;
            $target->last_sync_at = $source->last_sync_at;
        }

        return view('gate_devices.index', compact('devices'));
    }

    public function store(Request $request)
    {
        $this->authorizeGateDeviceManage();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $issued = GateDevice::issue($validated['name']);

        return redirect()
            ->route('gate_devices.index')
            ->with('success', 'Kiosk registered.')
            ->with('issued_token', $issued['plain_token'])
            ->with('issued_device_name', $issued['device']->name)
            ->with('issued_device_id', $issued['device']->id);
    }

    public function reissue(GateDevice $gateDevice)
    {
        $this->authorizeGateDeviceManage();

        $plain = $gateDevice->reissueToken();

        return redirect()
            ->route('gate_devices.index')
            ->with('success', 'New kiosk token generated. Old terminal/app tokens stop working.')
            ->with('issued_token', $plain)
            ->with('issued_device_name', $gateDevice->name)
            ->with('issued_device_id', $gateDevice->id);
    }

    public function update(Request $request, GateDevice $gateDevice)
    {
        $this->authorizeGateDeviceManage();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'required|boolean',
        ]);

        $gateDevice->update([
            'name' => $validated['name'],
            'is_active' => (bool) $validated['is_active'],
        ]);

        return back()->with('success', 'Kiosk updated.');
    }

    public function destroy(GateDevice $gateDevice)
    {
        $this->authorizeGateDeviceManage();

        $gateDevice->delete();

        return back()->with('success', 'Kiosk removed.');
    }

    /** Band admins must not mint school-wide gate tokens (full roster + write API). */
    protected function authorizeGateDeviceManage(): void
    {
        $user = auth()->user();
        if (! $user || (! $user->isSuperAdmin() && $user->role !== 'staff')) {
            abort(403, 'Only superadmin or staff can manage gate kiosk devices.');
        }
    }
}
