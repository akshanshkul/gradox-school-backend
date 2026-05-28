<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class TeacherAttendanceController extends Controller
{
    public function getStatus(Request $request)
    {
        $user = $request->user();
        $today = Carbon::today()->toDateString();

        $attendance = Attendance::where('user_id', $user->id)
            ->where('date', $today)
            ->first();

        return $this->successResponse([
            'attendance' => $attendance,
            'school_location' => [
                'latitude' => $user->school->latitude,
                'longitude' => $user->school->longitude,
                'radius' => $user->school->geofence_radius ?? 200,
            ]
        ]);
    }

    public function checkIn(Request $request)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'photo' => 'required|image|max:5120', // 5MB max
            'device_metadata' => 'nullable|array',
        ]);

        $user = $request->user();
        $school = $user->school;
        $today = Carbon::today()->toDateString();

        // 1. Geofencing check
        if ($school->latitude && $school->longitude) {
            $distance = $this->calculateDistance(
                $request->latitude,
                $request->longitude,
                $school->latitude,
                $school->longitude
            );

            $radius = $school->geofence_radius ?? 200;

            if ($distance > $radius) {
                return $this->errorResponse("You are outside the school boundary ($distance meters away).", 403);
            }
        }

        // 2. Prevent double check-in or if already marked by admin
        $attendance = Attendance::where('user_id', $user->id)
            ->where('date', $today)
            ->first();
 
        if ($attendance && ($attendance->check_in_time || $attendance->status)) {
            return $this->errorResponse("Attendance has already been marked for today.", 422);
        }

        // 3. Save photo
        $photoPath = $request->file('photo')->store('attendance/selfies', 's3');
        $photoUrl = Storage::disk('s3')->url($photoPath);

        // 4. Create record
        $attendance = Attendance::updateOrCreate(
            ['user_id' => $user->id, 'date' => $today, 'school_id' => $school->id],
            [
                'status' => 'present',
                'check_in_time' => Carbon::now()->toTimeString(),
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'photo_path' => $photoUrl,
                'device_metadata' => $request->device_metadata,
            ]
        );

        return $this->successResponse($attendance, "Checked in successfully.");
    }

    public function checkOut(Request $request)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        $user = $request->user();
        $school = $user->school;
        $today = Carbon::today()->toDateString();

        $attendance = Attendance::where('user_id', $user->id)
            ->where('date', $today)
            ->first();

        if (!$attendance || !$attendance->check_in_time) {
            return $this->errorResponse("You must check in before checking out.", 422);
        }

        if ($attendance->check_out_time) {
            return $this->errorResponse("You have already checked out today.", 422);
        }

        // Optional: Geofence for checkout too?
        if ($school->latitude && $school->longitude) {
            $distance = $this->calculateDistance(
                $request->latitude,
                $request->longitude,
                $school->latitude,
                $school->longitude
            );

            $radius = $school->geofence_radius ?? 200;

            if ($distance > $radius) {
                // We allow checkout but maybe flag it? For now, we allow it.
            }
        }

        $attendance->update([
            'check_out_time' => Carbon::now()->toTimeString(),
        ]);

        return $this->successResponse($attendance, "Checked out successfully.");
    }

    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000; // in meters

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c);
    }
}
