<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Acceptable GPS Accuracy in Meters
    |--------------------------------------------------------------------------
    |
    | If the device reports an accuracy uncertainty radius greater than this value,
    | the evidence will be classified as LOW_ACCURACY and will not automatically
    | verify attendance without HR review.
    |
    */
    'max_accuracy_meters' => (float) env('FIELD_ATTENDANCE_MAX_ACCURACY', 50.0),

    /*
    |--------------------------------------------------------------------------
    | Default Geofence Radius in Meters
    |--------------------------------------------------------------------------
    */
    'default_radius_meters' => (int) env('FIELD_ATTENDANCE_DEFAULT_RADIUS', 100),

    /*
    |--------------------------------------------------------------------------
    | Maximum Acceptable Client-Server Timestamp Drift (in seconds)
    |--------------------------------------------------------------------------
    |
    | 900 seconds = 15 minutes.
    |
    */
    'max_timestamp_drift_seconds' => (int) env('FIELD_ATTENDANCE_MAX_DRIFT_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | Maximum Plausible Ground Speed (in km/h) for Impossible Travel Detection
    |--------------------------------------------------------------------------
    */
    'max_travel_speed_kmh' => (float) env('FIELD_ATTENDANCE_MAX_SPEED_KMH', 200.0),

    /*
    |--------------------------------------------------------------------------
    | Photo Storage & Validation Configuration
    |--------------------------------------------------------------------------
    */
    'photo_disk' => env('FIELD_PHOTO_DISK', 'local'),
    'photo_directory' => 'field_photos',
    'max_photo_size_kb' => (int) env('FIELD_PHOTO_MAX_SIZE_KB', 5120), // 5 MB
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
];
