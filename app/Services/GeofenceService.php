<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\FieldAttendanceEvidence;
use App\Models\FieldLocation;
use Carbon\Carbon;

class GeofenceService
{
    /**
     * Calculate spherical distance between two sets of GPS coordinates using the Haversine formula.
     * Deterministic and server-side calculated.
     *
     * @param float $lat1
     * @param float $lon1
     * @param float $lat2
     * @param float $lon2
     * @return float Distance in meters
     */
    public function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // in meters

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return round($angle * $earthRadius, 2);
    }

    /**
     * Validate that coordinates are within valid geographic bounds.
     */
    public function isValidCoordinates(float $lat, float $lon): bool
    {
        return $lat >= -90.0 && $lat <= 90.0 && $lon >= -180.0 && $lon <= 180.0;
    }

    /**
     * Validate GPS coordinates and accuracy against a field location's geofence.
     */
    public function validateGeofence(float $lat, float $lon, float $accuracy, FieldLocation $location): array
    {
        if (!$this->isValidCoordinates($lat, $lon)) {
            return [
                'distance_meters' => 0.0,
                'geofence_result' => 'ANOMALY',
                'is_valid' => false,
                'reason' => 'Koordinat GPS tidak valid.',
            ];
        }

        $distance = $this->calculateDistance($lat, $lon, (float)$location->latitude, (float)$location->longitude);
        $maxAccuracy = (float) config('field_attendance.max_accuracy_meters', 50.0);
        $radius = (int) $location->radius_meters;

        // Check accuracy threshold
        if ($accuracy > $maxAccuracy || $accuracy <= 0) {
            return [
                'distance_meters' => $distance,
                'geofence_result' => 'LOW_ACCURACY',
                'is_valid' => false,
                'reason' => "Akurasi GPS ({$accuracy}m) melampaui batas toleransi sistem ({$maxAccuracy}m).",
            ];
        }

        // Check geofence radius
        if ($distance > $radius) {
            return [
                'distance_meters' => $distance,
                'geofence_result' => 'OUTSIDE_GEOFENCE',
                'is_valid' => false,
                'reason' => "Posisi Anda ({$distance}m) berada di luar radius geofence lokasi ({$radius}m).",
            ];
        }

        return [
            'distance_meters' => $distance,
            'geofence_result' => 'VALID',
            'is_valid' => true,
            'reason' => 'Verifikasi geofence berhasil.',
        ];
    }

    /**
     * Detect defensible anomalies such as impossible travel and timestamp drift.
     */
    public function detectAnomalies(
        Employee $employee,
        float $lat,
        float $lon,
        float $accuracy,
        Carbon $capturedAt,
        Carbon $serverAt
    ): array {
        $anomalies = [];

        // 1. Timestamp drift check
        $maxDrift = (int) config('field_attendance.max_timestamp_drift_seconds', 900);
        $driftSeconds = abs($serverAt->diffInSeconds($capturedAt));
        if ($driftSeconds > $maxDrift) {
            $anomalies[] = 'TIMESTAMP_DRIFT';
        }

        // 2. Unreasonable accuracy
        if ($accuracy <= 0 || $accuracy > 150) {
            $anomalies[] = 'UNREASONABLE_ACCURACY';
        }

        // 3. Impossible travel / rapid location jump
        $recentEvidence = FieldAttendanceEvidence::where('employee_id', $employee->id)
            ->where('captured_at', '>=', $capturedAt->copy()->subHours(2))
            ->where('captured_at', '<=', $capturedAt)
            ->latest('captured_at')
            ->first();

        if ($recentEvidence) {
            $prevLat = (float) $recentEvidence->latitude;
            $prevLon = (float) $recentEvidence->longitude;
            $distMeters = $this->calculateDistance($lat, $lon, $prevLat, $prevLon);

            $diffHours = max(0.001, $recentEvidence->captured_at->diffInSeconds($capturedAt) / 3600.0);
            $speedKmh = ($distMeters / 1000.0) / $diffHours;

            $maxSpeed = (float) config('field_attendance.max_travel_speed_kmh', 200.0);
            if ($speedKmh > $maxSpeed) {
                $anomalies[] = 'IMPOSSIBLE_TRAVEL';
            }
        }

        return $anomalies;
    }
}
