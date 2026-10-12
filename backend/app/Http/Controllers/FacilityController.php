<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class FacilityController extends Controller
{
    /**
     * Host: Create a new facility.
     */
    public function store(Request $request)
    {
        $host = Auth::guard('web')->user();

        $validator = Validator::make($request->all(), [
            'building' => 'required|string|max:255',
            'floor'    => 'required|string|max:255',
            'room'     => 'required|string|max:255',
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $facility = Facility::create([
            'building'   => $request->input('building'),
            'floor'      => $request->input('floor'),
            'room'       => $request->input('room'),
            'company_id' => $host->company_id,
            'created_by' => $host->id,
        ]);

        AuditLogger::log('facility_created', 'facilities', $facility->id, null, [
            'building' => $facility->building,
            'floor'    => $facility->floor,
            'room'     => $facility->room,
        ]);

        return back()->with('success', 'Facility added: ' . $facility->building . ' / ' . $facility->floor . ' / ' . $facility->room);
    }

    /**
     * Host: Update an existing facility.
     */
    public function update(Request $request, $id)
    {
        $facility = Facility::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'building' => 'required|string|max:255',
            'floor'    => 'required|string|max:255',
            'room'     => 'required|string|max:255',
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $facility->update([
            'building' => $request->input('building'),
            'floor'    => $request->input('floor'),
            'room'     => $request->input('room'),
        ]);

        AuditLogger::log('facility_updated', 'facilities', $facility->id, null, [
            'building' => $facility->building,
            'floor'    => $facility->floor,
            'room'     => $facility->room,
        ]);

        return back()->with('success', 'Facility updated.');
    }

    /**
     * Host: Delete a facility.
     */
    public function destroy($id)
    {
        $facility = Facility::findOrFail($id);

        AuditLogger::log('facility_deleted', 'facilities', $facility->id, null, [
            'building' => $facility->building,
            'floor'    => $facility->floor,
            'room'     => $facility->room,
        ]);

        $facility->delete();

        return back()->with('success', 'Facility deleted.');
    }
}
