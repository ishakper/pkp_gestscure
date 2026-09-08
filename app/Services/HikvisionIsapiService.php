<?php

namespace App\Services;

use App\Http\Controllers\Mock\HikvisionMockController;
use App\Models\Door;
use App\Models\Employee;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HikvisionIsapiService
{
    /**
     * Check if system is running in mock mode.
     */
    public function isMockMode(): bool
    {
        return (bool) config('services.hikvision.use_mock', env('HIKVISION_ISAPI_USE_MOCK', env('HIKVISION_MOCK_MODE', true)));
    }

    /**
     * Get mock base URL for local ISAPI simulation.
     */
    public function getMockBaseUrl(): string
    {
        $mockUrl = config('services.hikvision.mock_base_url');
        if (!empty($mockUrl)) {
            return rtrim($mockUrl, '/');
        }

        $appUrl = rtrim(config('app.url', 'http://localhost:8000'), '/');
        return "{$appUrl}/api/mock/isapi";
    }

    /**
     * Get device credentials based on Door model or default config.
     */
    public function getDeviceCredentials(?Door $door = null): array
    {
        if ($door && !empty($door->door_id)) {
            $code = strtoupper(str_replace('-', '_', $door->door_id)); // e.g. DOOR_A
            $userKey = "{$code}_USER";
            $passKey = "{$code}_PASS";

            return [
                'username' => env($userKey, config('services.hikvision.username', 'admin')),
                'password' => env($passKey, config('services.hikvision.password', 'Hikvision@DoorA')),
            ];
        }

        return [
            'username' => config('services.hikvision.username', env('HIKVISION_ISAPI_USERNAME', 'admin')),
            'password' => config('services.hikvision.password', env('HIKVISION_ISAPI_PASSWORD', 'Hikvision@DoorA')),
        ];
    }

    /**
     * Get device host and port.
     */
    public function getDeviceHostAndPort(?Door $door = null): array
    {
        $host = $door && !empty($door->device_ip) 
            ? $door->device_ip 
            : config('services.hikvision.host', env('HIKVISION_ISAPI_HOST', '192.168.90.11'));
        
        $port = (int) config('services.hikvision.port', env('HIKVISION_ISAPI_PORT', 80));

        return ['host' => $host, 'port' => $port];
    }

    /**
     * Build full URL for ISAPI endpoint.
     */
    public function buildUrl(string $endpoint, ?Door $door = null): string
    {
        $endpoint = '/' . ltrim($endpoint, '/');

        if ($this->isMockMode()) {
            return $this->getMockBaseUrl() . $endpoint;
        }

        ['host' => $host, 'port' => $port] = $this->getDeviceHostAndPort($door);
        $portSuffix = ($port && $port !== 80) ? ":{$port}" : '';

        return "http://{$host}{$portSuffix}/ISAPI{$endpoint}";
    }

    /**
     * Create configured HTTP client instance with timeouts and authentication.
     */
    protected function buildHttpClient(?Door $door = null): PendingRequest
    {
        $connectTimeout = (int) config('services.hikvision.connect_timeout', env('ISAPI_CONNECT_TIMEOUT', 5));
        $requestTimeout = (int) config('services.hikvision.request_timeout', env('ISAPI_REQUEST_TIMEOUT', 10));

        $client = Http::connectTimeout($connectTimeout)
            ->timeout($requestTimeout)
            ->acceptJson();

        if (!$this->isMockMode()) {
            $creds = $this->getDeviceCredentials($door);
            $client = $client->withDigestAuth($creds['username'], $creds['password']);
        }

        return $client;
    }

    /**
     * Retrieve device status and info from /System/deviceInfo endpoint.
     * In mock mode, directly invokes HikvisionMockController to prevent single-thread cURL deadlocks on php artisan serve.
     */
    public function getDeviceStatus(?Door $door = null): array
    {
        // 1. Mock Mode: Direct Internal Controller Invocation
        if ($this->isMockMode()) {
            // Simulated offline if IP ends with .99
            if ($door && !empty($door->device_ip) && str_ends_with($door->device_ip, '.99')) {
                return [
                    'status' => false,
                    'statusCode' => 500,
                    'data' => null,
                    'error' => "Simulated Device Offline ({$door->device_ip})",
                ];
            }

            try {
                $mockController = app(HikvisionMockController::class);
                $jsonResponse = $mockController->deviceStatus();
                $data = $jsonResponse->getData(true) ?? [];

                // Normalize model, serialNumber, firmware, and doorStatus
                $data['model'] = $data['model'] ?? ($data['DeviceInfo']['model'] ?? 'DS-K1T804AMF');
                $data['serialNumber'] = $data['serialNumber'] ?? ($data['DeviceInfo']['serialNumber'] ?? 'DS-K1T804AMF20260901');
                $data['firmware'] = $data['firmware'] ?? ($data['DeviceInfo']['firmwareVersion'] ?? 'V1.2.3');
                $data['doorStatus'] = $data['doorStatus'] ?? ($data['DeviceStatus']['doorStatus'] ?? 'closed');
                $data['online'] = true;

                return [
                    'status' => true,
                    'statusCode' => $data['statusCode'] ?? 1,
                    'data' => $data,
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                Log::error("ISAPI mock deviceStatus error: " . $e->getMessage());
                return [
                    'status' => false,
                    'statusCode' => 500,
                    'data' => null,
                    'error' => "ISAPI Mock Error: " . $e->getMessage(),
                ];
            }
        }

        // 2. Real Physical Device Mode: HTTP Request to /System/deviceInfo with Digest Auth
        $url = $this->buildUrl('/System/deviceInfo', $door);

        try {
            $response = $this->buildHttpClient($door)->get($url);

            if ($response->successful()) {
                $body = trim($response->body());
                $data = [];

                // Parse XML response if content contains XML tags
                if (str_contains($body, '<?xml') || str_contains($body, '<DeviceInfo') || str_starts_with($body, '<')) {
                    try {
                        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
                        if ($xml !== false) {
                            $data = json_decode(json_encode($xml), true) ?? [];
                        }
                    } catch (\Throwable $e) {
                        Log::warning("ISAPI XML parse warning ({$url}): " . $e->getMessage());
                    }
                }

                // Fallback to json if XML parsing was empty or not XML
                if (empty($data)) {
                    $data = $response->json() ?? [];
                }

                // Map model, serialNumber, firmware, and doorStatus cleanly
                $model = $data['model'] ?? ($data['deviceModel'] ?? ($data['DeviceInfo']['model'] ?? 'DS-K1T804AMF'));
                $serialNumber = $data['serialNumber'] ?? ($data['DeviceInfo']['serialNumber'] ?? null);
                $firmware = $data['firmwareVersion'] ?? ($data['firmware'] ?? ($data['DeviceInfo']['firmwareVersion'] ?? null));
                $doorStatus = $data['doorStatus'] ?? ($data['DeviceStatus']['doorStatus'] ?? 'closed');

                $data['model'] = $model;
                $data['serialNumber'] = $serialNumber;
                $data['firmware'] = $firmware;
                $data['doorStatus'] = $doorStatus;
                $data['online'] = true;

                return [
                    'status' => true,
                    'statusCode' => $data['statusCode'] ?? 1,
                    'data' => $data,
                    'error' => null,
                ];
            }

            // In case of non-200 HTTP response, attempt to parse error XML/JSON
            $body = trim($response->body());
            $errorData = [];
            if (str_contains($body, '<?xml') || str_starts_with($body, '<')) {
                try {
                    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
                    if ($xml !== false) {
                        $errorData = json_decode(json_encode($xml), true) ?? [];
                    }
                } catch (\Throwable $e) {
                    // Ignore error parse failure
                }
            }
            if (empty($errorData)) {
                $errorData = $response->json();
            }

            return [
                'status' => false,
                'statusCode' => $response->status(),
                'data' => $errorData,
                'error' => "HTTP {$response->status()}: " . ($errorData['statusString'] ?? $response->body()),
            ];
        } catch (\Throwable $e) {
            Log::error("ISAPI getDeviceStatus failed ({$url}): " . $e->getMessage());
            return [
                'status' => false,
                'statusCode' => 500,
                'data' => null,
                'error' => "ISAPI Connection Error: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Trigger remote control command on physical terminal (e.g. 'open' to unlock door).
     * Uses ISAPI PUT /AccessControl/RemoteControl/door/1 with Digest Auth and XML payload.
     */
    public function remoteControlDoor(Door $door, string $command = 'open'): array
    {
        // 1. Mock Mode: Return simulated success or offline error
        if ($this->isMockMode()) {
            if (!empty($door->device_ip) && str_ends_with($door->device_ip, '.99')) {
                return [
                    'status' => false,
                    'statusCode' => 500,
                    'error' => "Simulated Device Offline ({$door->device_ip})",
                ];
            }

            return [
                'status' => true,
                'statusCode' => 1,
                'message' => 'Simulated door unlock successful',
                'error' => null,
            ];
        }

        // 2. Real Physical Device Mode: PUT /ISAPI/AccessControl/RemoteControl/door/1
        $url = $this->buildUrl('/AccessControl/RemoteControl/door/1', $door);
        $xmlBody = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><RemoteControlDoor><cmd>{$command}</cmd></RemoteControlDoor>";

        try {
            $response = $this->buildHttpClient($door)
                ->withHeaders([
                    'Content-Type' => 'application/xml',
                    'Accept' => 'application/xml, text/xml, */*',
                ])
                ->withBody($xmlBody, 'application/xml')
                ->put($url);

            $body = $response->body();
            $isSuccessStatus = in_array($response->status(), [200, 204], true);
            $hasSuccessXml = stripos($body, '<statusString>OK</statusString>') !== false 
                || stripos($body, '<subStatusCode>ok</subStatusCode>') !== false;

            if ($isSuccessStatus || $hasSuccessXml) {
                return [
                    'status' => true,
                    'statusCode' => 200,
                    'message' => 'Door command executed successfully',
                    'error' => null,
                ];
            }

            return [
                'status' => false,
                'statusCode' => $response->status(),
                'error' => !empty($body) ? $body : "HTTP {$response->status()}: Remote control failed",
            ];
        } catch (\Throwable $e) {
            Log::error("ISAPI remoteControlDoor failed ({$url}): " . $e->getMessage());
            return [
                'status' => false,
                'statusCode' => 500,
                'error' => "ISAPI Connection Error: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Push / Synchronize user RFID card info to terminal via /AccessControl/CardInfo/Record (PUT).
     * In mock mode, directly invokes HikvisionMockController.
     */
    public function syncCardUser(string $employeeNo, string $cardNo, ?string $employeeName = null, ?Door $door = null): array
    {
        $payload = [
            'CardInfo' => [
                'employeeNo' => $employeeNo,
                'cardNo' => $cardNo,
                'cardType' => 'normalCard',
                'leaderCard' => 'false',
                'name' => $employeeName,
            ],
            'employeeNo' => $employeeNo,
            'cardNo' => $cardNo,
            'name' => $employeeName,
        ];

        // 1. Mock Mode: Direct Internal Controller Invocation
        if ($this->isMockMode()) {
            try {
                $request = Request::create('/api/mock/isapi/AccessControl/CardInfo/Record', 'PUT', $payload);
                $mockController = app(HikvisionMockController::class);
                $jsonResponse = $mockController->syncCard($request);
                $data = $jsonResponse->getData(true) ?? [];
                $httpStatus = $jsonResponse->getStatusCode();

                if ($httpStatus >= 200 && $httpStatus < 300) {
                    return [
                        'status' => true,
                        'statusCode' => $data['statusCode'] ?? 1,
                        'data' => $data,
                        'error' => null,
                    ];
                }

                return [
                    'status' => false,
                    'statusCode' => $httpStatus,
                    'data' => $data,
                    'error' => $data['errorMsg'] ?? ($data['ResponseStatus']['errorMsg'] ?? 'Card synchronization failed.'),
                ];
            } catch (\Throwable $e) {
                Log::error("ISAPI mock syncCardUser error: " . $e->getMessage());
                return [
                    'status' => false,
                    'statusCode' => 500,
                    'data' => null,
                    'error' => "ISAPI Mock Error: " . $e->getMessage(),
                ];
            }
        }

        // 2. Real Physical Device Mode
        $url = $this->buildUrl('/AccessControl/CardInfo/Record', $door);

        try {
            $response = $this->buildHttpClient($door)->put($url, $payload);
            $json = $response->json() ?? [];

            if ($response->successful()) {
                $statusCode = $json['statusCode'] ?? ($json['ResponseStatus']['statusCode'] ?? 1);
                $isOk = ($statusCode == 1) || (isset($json['statusString']) && strtoupper($json['statusString']) === 'OK');

                return [
                    'status' => $isOk,
                    'statusCode' => $statusCode,
                    'data' => $json,
                    'error' => $isOk ? null : ($json['errorMsg'] ?? ($json['ResponseStatus']['errorMsg'] ?? 'Card synchronization failed.')),
                ];
            }

            return [
                'status' => false,
                'statusCode' => $response->status(),
                'data' => $json,
                'error' => $json['errorMsg'] ?? ($json['ResponseStatus']['errorMsg'] ?? ($json['statusString'] ?? "HTTP {$response->status()}: " . $response->body())),
            ];
        } catch (\Throwable $e) {
            Log::error("ISAPI syncCardUser failed for Employee {$employeeNo} ({$url}): " . $e->getMessage());
            return [
                'status' => false,
                'statusCode' => 500,
                'data' => null,
                'error' => "ISAPI Connection Error: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch access events / tap logs from /AccessControl/AcsEvent (POST).
     * In mock mode, directly invokes HikvisionMockController.
     */
    public function fetchEvents(?int $limit = 20, ?Door $door = null): array
    {
        $payload = [
            'AcsEventCond' => [
                'searchID' => (string) Str::uuid(),
                'searchResultPosition' => 0,
                'maxResults' => $limit ?: 20,
                'major' => 5, // Access Control Event
                'minor' => 0,
            ],
        ];

        // 1. Mock Mode: Direct Internal Controller Invocation
        if ($this->isMockMode()) {
            try {
                $request = Request::create('/api/mock/isapi/AccessControl/AcsEvent', 'POST', $payload);
                $mockController = app(HikvisionMockController::class);
                $jsonResponse = $mockController->fetchAccessLogs($request);
                $data = $jsonResponse->getData(true) ?? [];

                $rawList = $data['AcsEvent']['InfoList'] ?? ($data['events'] ?? ($data['InfoList'] ?? []));
                $formattedEvents = [];

                foreach ($rawList as $item) {
                    $formattedEvents[] = [
                        'major' => $item['major'] ?? null,
                        'minor' => $item['minor'] ?? null,
                        'time' => $item['time'] ?? now()->toIso8601String(),
                        'card_no' => $item['cardNo'] ?? ($item['card_no'] ?? null),
                        'employee_no' => $item['employeeNoString'] ?? ($item['employeeNo'] ?? ($item['employee_no'] ?? null)),
                        'name' => $item['name'] ?? null,
                        'verify_method' => $item['verifyMethod'] ?? (isset($item['currentVerifyMode']) ? ucfirst($item['currentVerifyMode']) : 'Card'),
                        'door_no' => $item['doorNo'] ?? ($item['door_no'] ?? null),
                        'door_name' => $item['doorName'] ?? ($item['door_name'] ?? null),
                        'access_status' => $item['accessStatus'] ?? ($item['access_status'] ?? 'Granted'),
                    ];
                }

                return [
                    'status' => true,
                    'statusCode' => 1,
                    'total' => count($formattedEvents),
                    'events' => $formattedEvents,
                    'data' => $data,
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                Log::error("ISAPI mock fetchEvents error: " . $e->getMessage());
                return [
                    'status' => false,
                    'statusCode' => 500,
                    'total' => 0,
                    'events' => [],
                    'data' => null,
                    'error' => "ISAPI Mock Error: " . $e->getMessage(),
                ];
            }
        }

        // 2. Real Physical Device Mode
        $url = $this->buildUrl('/AccessControl/AcsEvent', $door);

        try {
            $response = $this->buildHttpClient($door)->post($url, $payload);
            $json = $response->json() ?? [];

            if ($response->successful()) {
                $rawList = $json['AcsEvent']['InfoList'] ?? ($json['events'] ?? ($json['InfoList'] ?? []));
                $formattedEvents = [];

                foreach ($rawList as $item) {
                    $formattedEvents[] = [
                        'major' => $item['major'] ?? null,
                        'minor' => $item['minor'] ?? null,
                        'time' => $item['time'] ?? now()->toIso8601String(),
                        'card_no' => $item['cardNo'] ?? ($item['card_no'] ?? null),
                        'employee_no' => $item['employeeNoString'] ?? ($item['employeeNo'] ?? ($item['employee_no'] ?? null)),
                        'name' => $item['name'] ?? null,
                        'verify_method' => $item['verifyMethod'] ?? (isset($item['currentVerifyMode']) ? ucfirst($item['currentVerifyMode']) : 'Card'),
                        'door_no' => $item['doorNo'] ?? ($item['door_no'] ?? null),
                        'door_name' => $item['doorName'] ?? ($item['door_name'] ?? null),
                        'access_status' => $item['accessStatus'] ?? ($item['access_status'] ?? 'Granted'),
                    ];
                }

                return [
                    'status' => true,
                    'statusCode' => 1,
                    'total' => count($formattedEvents),
                    'events' => $formattedEvents,
                    'data' => $json,
                    'error' => null,
                ];
            }

            return [
                'status' => false,
                'statusCode' => $response->status(),
                'total' => 0,
                'events' => [],
                'data' => $json,
                'error' => "HTTP {$response->status()}: " . ($json['errorMsg'] ?? $response->body()),
            ];
        } catch (\Throwable $e) {
            Log::error("ISAPI fetchEvents failed ({$url}): " . $e->getMessage());
            return [
                'status' => false,
                'statusCode' => 500,
                'total' => 0,
                'events' => [],
                'data' => null,
                'error' => "ISAPI Connection Error: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Backward-compatible helper: Ping physical terminal device status.
     */
    public function pingDevice(Door $door): bool
    {
        // Special testing case: simulate offline if IP ends with .99
        if (!empty($door->device_ip) && str_ends_with($door->device_ip, '.99')) {
            return false;
        }

        $result = $this->getDeviceStatus($door);
        return (bool) ($result['status'] ?? false);
    }

    /**
     * Backward-compatible helper: Push employee user record to Hikvision terminal.
     */
    public function setUser(Door $door, Employee $employee): array
    {
        return $this->syncCardUser(
            $employee->employee_id ?? $employee->nik,
            $employee->card_no ?? '',
            $employee->name,
            $door
        );
    }

    /**
     * Backward-compatible helper: Push door access rights plan for employee.
     */
    public function setUserAccessRight(Door $door, Employee $employee): array
    {
        if ($this->isMockMode()) {
            Log::info("[ISAPI MOCK] Assigning access right for employee {$employee->employee_id} on Door {$door->door_id}");
            return ['status' => true, 'statusCode' => 1, 'statusString' => 'OK'];
        }

        $url = $this->buildUrl('/AccessControl/UserRightPlan/SetUp?format=json', $door);
        $payload = [
            'UserRightPlan' => [
                'employeeNo' => $employee->employee_id ?? $employee->nik,
                'enable' => true,
                'planNo' => 1,
                'userRightType' => 'normal',
            ],
        ];

        try {
            $response = $this->buildHttpClient($door)->put($url, $payload);
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
