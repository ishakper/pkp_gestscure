<?php

namespace App\Services;

use App\Models\Door;
use App\Models\Employee;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HikvisionIsapiService
{
    /**
     * Get device credentials from env based on door_id (DOOR-A..DOOR-D).
     */
    protected function getDeviceCredentials(Door $door): array
    {
        $code = strtoupper(str_replace('-', '_', $door->door_id)); // e.g. DOOR_A
        $userKey = "{$code}_USER";
        $passKey = "{$code}_PASS";

        return [
            'username' => env($userKey, 'admin'),
            'password' => env($passKey, 'secret123'),
        ];
    }

    /**
     * Check if system is running in mock mode.
     */
    public function isMockMode(): bool
    {
        return config('app.env') === 'testing' || env('HIKVISION_MOCK_MODE', true);
    }

    /**
     * Ping physical terminal device status via ISAPI.
     */
    public function pingDevice(Door $door): bool
    {
        if ($this->isMockMode()) {
            // In mock mode, pretend all doors are online except if IP ends with .99 for testing
            return !str_ends_with($door->device_ip, '.99');
        }

        $creds = $this->getDeviceCredentials($door);
        $url = "http://{$door->device_ip}/ISAPI/System/status";
        $connectTimeout = (int) env('ISAPI_CONNECT_TIMEOUT', 3);
        $requestTimeout = (int) env('ISAPI_REQUEST_TIMEOUT', 5);

        try {
            $response = Http::connectTimeout($connectTimeout)
                ->timeout($requestTimeout)
                ->withDigestAuth($creds['username'], $creds['password'])
                ->get($url);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning("ISAPI Ping failed for Door {$door->door_id} ({$door->device_ip}): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Push employee user record to Hikvision terminal via ISAPI.
     */
    public function setUser(Door $door, Employee $employee): array
    {
        if ($this->isMockMode()) {
            Log::info("[ISAPI MOCK] Setting user {$employee->employee_id} ({$employee->name}) on Door {$door->door_id}");
            return ['status' => true, 'statusCode' => 1, 'statusString' => 'OK'];
        }

        $creds = $this->getDeviceCredentials($door);
        $url = "http://{$door->device_ip}/ISAPI/AccessControl/UserInfo/SetUp?format=json";
        $connectTimeout = (int) env('ISAPI_CONNECT_TIMEOUT', 3);
        $requestTimeout = (int) env('ISAPI_REQUEST_TIMEOUT', 5);

        $payload = [
            'UserInfo' => [
                'employeeNo' => $employee->employee_id,
                'name' => $employee->name,
                'userType' => 'normal',
                'closeDelayEnabled' => false,
                'Valid' => [
                    'enable' => true,
                    'beginTime' => '2020-01-01T00:00:00',
                    'endTime' => '2035-12-31T23:59:59',
                    'timeType' => 'local',
                ],
                'belongGroup' => 1,
            ],
        ];

        try {
            $response = Http::connectTimeout($connectTimeout)
                ->timeout($requestTimeout)
                ->withDigestAuth($creds['username'], $creds['password'])
                ->put($url, $payload);

            if ($response->successful()) {
                return ['status' => true, 'data' => $response->json()];
            }

            return ['status' => false, 'error' => $response->body()];
        } catch (\Throwable $e) {
            Log::error("ISAPI setUser failed for Door {$door->door_id}: " . $e->getMessage());
            return ['status' => false, 'error' => "ISAPI Connection Error ({$door->device_ip}): " . $e->getMessage()];
        }
    }

    /**
     * Push door access rights / privilege plan for employee via ISAPI.
     */
    public function setUserAccessRight(Door $door, Employee $employee): array
    {
        if ($this->isMockMode()) {
            Log::info("[ISAPI MOCK] Assigning access right for employee {$employee->employee_id} on Door {$door->door_id}");
            return ['status' => true, 'statusCode' => 1, 'statusString' => 'OK'];
        }

        $creds = $this->getDeviceCredentials($door);
        $url = "http://{$door->device_ip}/ISAPI/AccessControl/UserRightPlan/SetUp?format=json";
        $connectTimeout = (int) env('ISAPI_CONNECT_TIMEOUT', 3);
        $requestTimeout = (int) env('ISAPI_REQUEST_TIMEOUT', 5);

        $payload = [
            'UserRightPlan' => [
                'employeeNo' => $employee->employee_id,
                'enable' => true,
                'planNo' => 1,
                'userRightType' => 'normal',
            ],
        ];

        try {
            $response = Http::connectTimeout($connectTimeout)
                ->timeout($requestTimeout)
                ->withDigestAuth($creds['username'], $creds['password'])
                ->put($url, $payload);

            if ($response->successful()) {
                return ['status' => true, 'data' => $response->json()];
            }

            return ['status' => false, 'error' => $response->body()];
        } catch (\Throwable $e) {
            Log::error("ISAPI setUserAccessRight failed for Door {$door->door_id}: " . $e->getMessage());
            return ['status' => false, 'error' => "ISAPI Connection Error ({$door->device_ip}): " . $e->getMessage()];
        }
    }
}
