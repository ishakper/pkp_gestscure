<?php

namespace App\Services;

use App\Http\Controllers\Mock\HikvisionMockController;
use App\Models\Door;
use App\Models\Employee;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HikvisionIsapiService
{
    private const ALLOWED_REMOTE_COMMANDS = ['open'];

    /**
     * Check if system is running in mock mode.
     */
    public function isMockMode(): bool
    {
        return (bool) config('services.hikvision.use_mock', true);
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
        if ($door) {
            if (!empty($door->isapi_username) && !empty($door->isapi_password)) {
                $password = $door->isapi_password;
                // If model attribute was accessed uncast or has an encrypted payload, attempt fallback decrypt
                if (is_string($password) && str_starts_with($password, 'eyJ')) {
                    try {
                        $password = Crypt::decryptString($password);
                    } catch (\Throwable) {
                        // Raw string fallback
                    }
                }
                return ['username' => $door->isapi_username, 'password' => $password];
            }

            if (!empty($door->door_id)) {
                $doorKey = strtoupper($door->door_id); // e.g. DOOR-A or DOOR-B
                $doorConfig = config("services.doors.{$doorKey}") ?? config("services.doors.{$door->door_id}");

                $username = $doorConfig['username'] ?: config('services.hikvision.username');
                $password = $doorConfig['password'] ?: config('services.hikvision.password');
            } else {
                $username = config('services.hikvision.username');
                $password = config('services.hikvision.password');
            }
        } else {
            $username = config('services.hikvision.username');
            $password = config('services.hikvision.password');
        }

        if (!is_string($username) || trim($username) === '' || !is_string($password) || $password === '') {
            throw new \RuntimeException('Hikvision credentials are not configured for this device.');
        }

        return ['username' => $username, 'password' => $password];
    }

    /**
     * Get device host and port.
     */
    public function getDeviceHostAndPort(?Door $door = null): array
    {
        $doorKey = $door && !empty($door->door_id) ? strtoupper($door->door_id) : null;
        $configuredIp = $doorKey ? config("services.doors.{$doorKey}.ip") : null;

        $host = $door && !empty($door->device_ip)
            ? $door->device_ip
            : ($configuredIp ?: config('services.hikvision.host', '192.168.90.11'));

        $port = (int) config('services.hikvision.port', 80);

        return ['host' => $host, 'port' => $port];
    }

    /**
     * Get device channel (ISAPI RemoteControl door number) from trusted server-side configuration.
     * Never derives channel from frontend or untrusted sources.
     *
     * @param Door $door Trusted server-side Door model instance
     * @return int Device channel number (default: 1)
     */
    public function getDoorDeviceChannel(Door $door): int
    {
        // 1. Check door-specific configuration
        if (!empty($door->door_id)) {
            $doorKey = strtoupper($door->door_id);
            $configuredChannel = config("services.doors.{$doorKey}.channel");
            if (is_numeric($configuredChannel)) {
                return (int) $configuredChannel;
            }
        }

        // 2. Check if Door model has device_channel attribute (future schema extension)
        if (isset($door->device_channel) && is_numeric($door->device_channel)) {
            return (int) $door->device_channel;
        }

        // 3. Default to channel 1 (safe fallback)
        return 1;
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
        $connectTimeout = (int) config('services.hikvision.connect_timeout', 5);
        $requestTimeout = (int) config('services.hikvision.request_timeout', 10);

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

                // Normalize model, serialNumber, firmware, and doorStatus (with null safety)
                $data['model'] = $data['model'] ?? (isset($data['DeviceInfo']) && is_array($data['DeviceInfo']) ? ($data['DeviceInfo']['model'] ?? 'DS-K1T804AMF') : 'DS-K1T804AMF');
                $data['serialNumber'] = $data['serialNumber'] ?? (isset($data['DeviceInfo']) && is_array($data['DeviceInfo']) ? ($data['DeviceInfo']['serialNumber'] ?? 'DS-K1T804AMF20260901') : 'DS-K1T804AMF20260901');
                $data['firmware'] = $data['firmware'] ?? (isset($data['DeviceInfo']) && is_array($data['DeviceInfo']) ? ($data['DeviceInfo']['firmwareVersion'] ?? 'V1.2.3') : 'V1.2.3');
                $data['doorStatus'] = $data['doorStatus'] ?? (isset($data['DeviceStatus']) && is_array($data['DeviceStatus']) ? ($data['DeviceStatus']['doorStatus'] ?? 'closed') : 'closed');
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

                // Map model, serialNumber, firmware, and doorStatus cleanly (with null safety)
                $model = $data['model'] ?? ($data['deviceModel'] ?? ($data['DeviceInfo']['model'] ?? 'DS-K1T804AMF'));
                $serialNumber = $data['serialNumber'] ?? ($data['DeviceInfo']['serialNumber'] ?? null);
                $firmware = $data['firmwareVersion'] ?? ($data['firmware'] ?? ($data['DeviceInfo']['firmwareVersion'] ?? null));
                $doorStatus = $data['doorStatus'] ?? (isset($data['DeviceStatus']) && is_array($data['DeviceStatus']) ? ($data['DeviceStatus']['doorStatus'] ?? 'closed') : 'closed');

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
     * Test connection to a new device IP with ISAPI credentials via /System/deviceInfo endpoint (Read-Only).
     */
    public function testDeviceConnection(string $ip, ?string $username = null, ?string $password = null): array
    {
        $username = $username ?: config('services.hikvision.username');
        $password = $password ?: config('services.hikvision.password');

        if ($this->isMockMode()) {
            if (!empty($ip) && str_ends_with($ip, '.99')) {
                return [
                    'status' => false,
                    'statusCode' => 500,
                    'data' => null,
                    'error' => "Simulated Device Offline ({$ip})",
                ];
            }

            try {
                $mockController = app(HikvisionMockController::class);
                $jsonResponse = $mockController->deviceStatus();
                $data = $jsonResponse->getData(true) ?? [];

                $data['model'] = $data['model'] ?? ($data['DeviceInfo']['model'] ?? 'DS-K1T804AMF');
                $data['serialNumber'] = $data['serialNumber'] ?? ($data['DeviceInfo']['serialNumber'] ?? 'DS-K1T804AMF' . date('Ymd'));
                $data['firmware'] = $data['firmware'] ?? ($data['DeviceInfo']['firmwareVersion'] ?? 'V1.2.3');
                $data['online'] = true;

                return [
                    'status' => true,
                    'statusCode' => 200,
                    'data' => $data,
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                return [
                    'status' => false,
                    'statusCode' => 500,
                    'data' => null,
                    'error' => "ISAPI Mock Error: " . $e->getMessage(),
                ];
            }
        }

        $port = (int) config('services.hikvision.port', 80);
        $portSuffix = ($port && $port !== 80) ? ":{$port}" : '';
        $url = "http://{$ip}{$portSuffix}/ISAPI/System/deviceInfo";

        try {
            $connectTimeout = (int) config('services.hikvision.connect_timeout', 5);
            $requestTimeout = (int) config('services.hikvision.request_timeout', 10);

            $response = Http::connectTimeout($connectTimeout)
                ->timeout($requestTimeout)
                ->acceptJson()
                ->withDigestAuth($username, $password)
                ->get($url);

            if ($response->successful()) {
                $xml = @simplexml_load_string($response->body());
                $data = $xml ? json_decode(json_encode($xml), true) : $response->json();

                return [
                    'status' => true,
                    'statusCode' => $response->status(),
                    'data' => [
                        'model' => $data['model'] ?? ($data['DeviceInfo']['model'] ?? 'DS-K1T804AMF'),
                        'serialNumber' => $data['serialNumber'] ?? ($data['DeviceInfo']['serialNumber'] ?? 'UNKNOWN'),
                        'firmware' => $data['firmwareVersion'] ?? ($data['DeviceInfo']['firmwareVersion'] ?? 'V1.0.0'),
                        'online' => true,
                    ],
                    'error' => null,
                ];
            }

            return [
                'status' => false,
                'statusCode' => $response->status(),
                'data' => null,
                'error' => "HTTP {$response->status()}: " . $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'statusCode' => 500,
                'data' => null,
                'error' => "Connection failed: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Test manual device connection (read-only) with custom per-door parameters.
     * SSRF-protected: rejects loopback, link-local, multicast, broadcast, cloud metadata.
     * Validates: IP format, port range (1-65535), timeout range (1-30).
     * 
     * @param string $ip Device IP address
     * @param int $port Device port (1-65535)
     * @param string $scheme Connection scheme (http|https)
     * @param int $timeout Connection + read timeout (1-30 seconds)
     * @param string|null $username ISAPI username
     * @param string|null $password ISAPI password (plain or encrypted)
     * @param bool $verify_tls TLS certificate verification toggle
     */
    public function testManualDeviceConnection(
        string $ip,
        int $port,
        string $scheme,
        int $timeout,
        ?string $username = null,
        ?string $password = null,
        bool $verify_tls = true
    ): array {
        // Input validation
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['status' => false, 'statusCode' => 422, 'error' => 'Invalid IP address format', 'data' => null];
        }

        // SSRF protection: reject dangerous IP ranges
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return ['status' => false, 'statusCode' => 422, 'error' => 'Invalid IP address', 'data' => null];
        }

        // Reject loopback (127.0.0.0/8)
        if (($ipLong >= ip2long('127.0.0.0') && $ipLong <= ip2long('127.255.255.255'))) {
            return ['status' => false, 'statusCode' => 422, 'error' => 'Loopback IP addresses not allowed', 'data' => null];
        }

        // Reject link-local (169.254.0.0/16)
        if (($ipLong >= ip2long('169.254.0.0') && $ipLong <= ip2long('169.254.255.255'))) {
            return ['status' => false, 'statusCode' => 422, 'error' => 'Link-local IP addresses not allowed', 'data' => null];
        }

        // Reject multicast (224.0.0.0/4)
        if (($ipLong >= ip2long('224.0.0.0') && $ipLong <= ip2long('239.255.255.255'))) {
            return ['status' => false, 'statusCode' => 422, 'error' => 'Multicast IP addresses not allowed', 'data' => null];
        }

        // Reject broadcast
        if ($ip === '255.255.255.255') {
            return ['status' => false, 'statusCode' => 422, 'error' => 'Broadcast IP address not allowed', 'data' => null];
        }

        // Reject cloud metadata endpoint
        if ($ip === '169.254.169.254') {
            return ['status' => false, 'statusCode' => 422, 'error' => 'Cloud metadata endpoint not allowed', 'data' => null];
        }

        // Validate port
        if ($port < 1 || $port > 65535) {
            return ['status' => false, 'statusCode' => 422, 'error' => 'Port must be between 1 and 65535', 'data' => null];
        }

        // Validate timeout
        if ($timeout < 1 || $timeout > 30) {
            return ['status' => false, 'statusCode' => 422, 'error' => 'Timeout must be between 1 and 30 seconds', 'data' => null];
        }

        // Validate scheme
        $scheme = strtolower($scheme);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['status' => false, 'statusCode' => 422, 'error' => 'Scheme must be http or https', 'data' => null];
        }

        // Use provided credentials or fall back to config
        $username = $username ?: config('services.hikvision.username');
        $password = $password ?: config('services.hikvision.password');

        // Handle encrypted password (Laravel encrypted cast)
        if ($password && str_starts_with($password, 'eyJ')) {
            try {
                $password = Crypt::decryptString($password);
            } catch (\Throwable) {
                // If decrypt fails, use as-is (might be plaintext from test request)
            }
        }

        // Mock mode
        if ($this->isMockMode()) {
            if (str_ends_with($ip, '.99')) {
                return [
                    'status' => false,
                    'statusCode' => 500,
                    'error' => "Simulated Device Offline ({$ip}:{$port})",
                    'data' => null,
                ];
            }

            try {
                $mockController = app(HikvisionMockController::class);
                $jsonResponse = $mockController->deviceStatus();
                $data = $jsonResponse->getData(true) ?? [];

                $data['model'] = $data['model'] ?? ($data['DeviceInfo']['model'] ?? 'DS-K1T804AMF');
                $data['serialNumber'] = $data['serialNumber'] ?? ($data['DeviceInfo']['serialNumber'] ?? 'UNKNOWN');
                $data['firmware'] = $data['firmware'] ?? ($data['DeviceInfo']['firmwareVersion'] ?? 'V1.0.0');
                $data['online'] = true;

                return [
                    'status' => true,
                    'statusCode' => 200,
                    'error' => null,
                    'data' => $data,
                ];
            } catch (\Throwable $e) {
                return [
                    'status' => false,
                    'statusCode' => 500,
                    'error' => "Mock Error: " . $e->getMessage(),
                    'data' => null,
                ];
            }
        }

        // Real device connection with manual parameters
        $portSuffix = ($port && $port !== 80) ? ":{$port}" : '';
        $url = "{$scheme}://{$ip}{$portSuffix}/ISAPI/System/deviceInfo";

        try {
            $httpClient = Http::connectTimeout($timeout)->timeout($timeout)->acceptJson();

            // TLS verification toggle
            if (!$verify_tls) {
                $httpClient = $httpClient->withoutVerifying();
            }

            $response = $httpClient
                ->withDigestAuth($username, $password)
                ->get($url);

            if ($response->successful()) {
                $xml = @simplexml_load_string($response->body());
                $data = $xml ? json_decode(json_encode($xml), true) : $response->json();

                return [
                    'status' => true,
                    'statusCode' => $response->status(),
                    'error' => null,
                    'data' => [
                        'model' => $data['model'] ?? ($data['DeviceInfo']['model'] ?? 'DS-K1T804AMF'),
                        'serialNumber' => $data['serialNumber'] ?? ($data['DeviceInfo']['serialNumber'] ?? 'UNKNOWN'),
                        'firmware' => $data['firmwareVersion'] ?? ($data['DeviceInfo']['firmwareVersion'] ?? 'V1.0.0'),
                        'online' => true,
                    ],
                ];
            }

            return [
                'status' => false,
                'statusCode' => $response->status(),
                'error' => "HTTP {$response->status()}: " . substr($response->body(), 0, 200),
                'data' => null,
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return [
                'status' => false,
                'statusCode' => 504,
                'error' => 'Connection timeout or device unreachable',
                'data' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning("Manual device connection test failed for {$ip}:{$port}: {$e->getMessage()}");
            return [
                'status' => false,
                'statusCode' => 500,
                'error' => 'Connection failed: ' . substr($e->getMessage(), 0, 100),
                'data' => null,
            ];
        }
    }

    /**
     * Trigger remote control command on physical terminal (e.g. 'open' to unlock door).
     * Uses ISAPI PUT /AccessControl/RemoteControl/door/{channel} with Digest Auth and XML payload.
     * Channel derived from trusted Door configuration, never arbitrary frontend input.
     */
    public function remoteControlDoor(Door $door, string $command = 'open'): array
    {
        $command = strtolower(trim($command));
        if (!in_array($command, self::ALLOWED_REMOTE_COMMANDS, true)) {
            return [
                'status' => false,
                'statusCode' => 422,
                'error' => 'Unsupported remote door command.',
            ];
        }

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

        // 2. Real Physical Device Mode: Derive device channel from trusted server-side configuration
        // Default to channel 1 if not explicitly configured. Channel is per-door, never from frontend.
        $deviceChannel = $this->getDoorDeviceChannel($door);

        // 3. PUT /ISAPI/AccessControl/RemoteControl/door/{channel}
        $url = $this->buildUrl("/AccessControl/RemoteControl/door/{$deviceChannel}", $door);
        $xmlBody = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><RemoteControlDoor><cmd>" . htmlspecialchars($command, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</cmd></RemoteControlDoor>";

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

            if ($isSuccessStatus && ($hasSuccessXml || ($response->status() === 204 && trim($body) === ''))) {
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
     * Read device user metadata without biometric templates or credential material.
     */
    public function fetchUsers(Door $door, int $limit = 100): array
    {
        if ($this->isMockMode()) {
            return ['status' => false, 'total_device_matches' => 0, 'inspected_users' => 0, 'users' => [], 'error' => 'Device user inventory requires --real mode.'];
        }

        $url = $this->buildUrl('/AccessControl/UserInfo/Search?format=json', $door);
        $limit = max(1, min($limit, 1000));
        $searchId = str_replace('-', '', (string) Str::uuid());
        $position = 0;
        $totalMatches = 0;
        $users = [];

        try {
            $client = $this->buildHttpClient($door);
            $compatibilityProbed = false;

            while (count($users) < $limit) {
                $payload = ['UserInfoSearchCond' => [
                    'searchID' => $searchId,
                    'searchResultPosition' => $position,
                    'maxResults' => 10,
                ]];
                $response = $client->post($url, $payload);
                $json = $response->json() ?? [];

                if (!$response->successful()) {
                    $error = $json['ResponseStatus'] ?? $json;
                    $unsupported = strcasecmp((string) ($error['subStatusCode'] ?? ''), 'notSupport') === 0
                        || strcasecmp((string) ($error['errorCode'] ?? ''), '0x40000001') === 0;
                    $requiresCompatibilityProbe = !$compatibilityProbed
                        && !$unsupported
                        && $response->status() === 400
                        && str_contains($response->body(), '0x60000001');

                    if ($requiresCompatibilityProbe) {
                        $compatibilityProbed = true;
                        $response = $client->post($this->buildUrl('/AccessControl/UserInfo/Search', $door), $payload);
                        $json = $response->json() ?? [];
                        $error = $json['ResponseStatus'] ?? $json;
                        $unsupported = strcasecmp((string) ($error['subStatusCode'] ?? ''), 'notSupport') === 0
                            || strcasecmp((string) ($error['errorCode'] ?? ''), '0x40000001') === 0;
                    }

                    if (!$response->successful()) {
                        return [
                            'status' => false,
                            'unsupported' => $unsupported,
                            'total_device_matches' => 0,
                            'inspected_users' => 0,
                            'users' => [],
                            'error' => $unsupported ? 'User directory unsupported.' : "HTTP {$response->status()}: " . ($error['errorMsg'] ?? $error['statusString'] ?? 'User inventory failed.'),
                        ];
                    }
                }

                $container = $json['UserInfoSearch'] ?? $json;
                $rows = $container['UserInfo'] ?? [];
                if (isset($rows['employeeNo'])) {
                    $rows = [$rows];
                }
                $rows = array_values(array_filter($rows, 'is_array'));
                $numOfMatches = (int) ($container['numOfMatches'] ?? count($rows));
                $totalMatches = (int) ($container['totalMatches'] ?? $totalMatches ?: count($rows));
                $remaining = $limit - count($users);

                foreach (array_slice($rows, 0, $remaining) as $user) {
                    $users[] = [
                        'employee_no' => (string) ($user['employeeNo'] ?? ''),
                        'name' => (string) ($user['name'] ?? ''),
                        'status' => (string) ($user['Valid']['enable'] ?? $user['enable'] ?? ''),
                        'card_count' => isset($user['numOfCard']) ? (int) $user['numOfCard'] : null,
                    ];
                }

                $position += $numOfMatches;
                $responseStatus = strtoupper((string) ($container['responseStatusStrg'] ?? ''));
                if ($numOfMatches <= 0 || $position >= $totalMatches || in_array($responseStatus, ['OK', 'NO MATCH'], true)) {
                    break;
                }
            }

            return [
                'status' => true,
                'unsupported' => false,
                'total_device_matches' => $totalMatches,
                'inspected_users' => count($users),
                'users' => $users,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::error("ISAPI fetchUsers failed ({$url}): " . $e->getMessage());
            return ['status' => false, 'unsupported' => false, 'total_device_matches' => 0, 'inspected_users' => 0, 'users' => [], 'error' => 'ISAPI user inventory request failed.'];
        }
    }

    /**
     * Read device card inventory metadata without exposing plaintext credential values or card numbers.
     * Returns only privacy-safe aggregates and presence mappings per employeeNo.
     */
    public function fetchCards(Door $door, int $limit = 100): array
    {
        if ($this->isMockMode()) {
            return ['status' => false, 'total_device_matches' => 0, 'inspected_cards' => 0, 'cards' => [], 'error' => 'Device card inventory requires non-mock mode.'];
        }

        $url = $this->buildUrl('/AccessControl/CardInfo/Search?format=json', $door);
        $limit = max(1, min($limit, 1000));
        $searchId = str_replace('-', '', (string) Str::uuid());
        $position = 0;
        $totalMatches = 0;
        $cards = [];

        try {
            $client = $this->buildHttpClient($door);

            while (count($cards) < $limit) {
                $payload = ['CardInfoSearchCond' => [
                    'searchID' => $searchId,
                    'searchResultPosition' => $position,
                    'maxResults' => 10,
                ]];
                $response = $client->post($url, $payload);
                $json = $response->json() ?? [];

                if (!$response->successful()) {
                    $error = $json['ResponseStatus'] ?? $json;
                    $unsupported = strcasecmp((string) ($error['subStatusCode'] ?? ''), 'notSupport') === 0
                        || strcasecmp((string) ($error['errorCode'] ?? ''), '0x40000001') === 0;

                    return [
                        'status' => false,
                        'unsupported' => $unsupported,
                        'total_device_matches' => 0,
                        'inspected_cards' => 0,
                        'cards' => [],
                        'error' => $unsupported ? 'Card directory unsupported.' : "HTTP {$response->status()}: " . ($error['errorMsg'] ?? $error['statusString'] ?? 'Card inventory failed.'),
                    ];
                }

                $container = $json['CardInfoSearch'] ?? $json;
                $rows = $container['CardInfo'] ?? [];
                if (isset($rows['cardNo']) || isset($rows['employeeNo'])) {
                    $rows = [$rows];
                }
                $rows = array_values(array_filter($rows, 'is_array'));
                $numOfMatches = (int) ($container['numOfMatches'] ?? count($rows));
                $totalMatches = (int) ($container['totalMatches'] ?? $totalMatches ?: count($rows));
                $remaining = $limit - count($cards);

                foreach (array_slice($rows, 0, $remaining) as $card) {
                    $employeeNo = trim((string) ($card['employeeNo'] ?? ''));
                    $cardType = (string) ($card['cardType'] ?? 'normalCard');
                    // Immediately drop cardNo; only store boolean presence and safe type descriptor
                    $cards[] = [
                        'employee_no' => $employeeNo,
                        'card_registered' => true,
                        'card_type' => $cardType,
                    ];
                }

                $position += $numOfMatches;
                $responseStatus = strtoupper((string) ($container['responseStatusStrg'] ?? ''));
                if ($numOfMatches <= 0 || $position >= $totalMatches || in_array($responseStatus, ['OK', 'NO MATCH'], true)) {
                    break;
                }
            }

            return [
                'status' => true,
                'unsupported' => false,
                'total_device_matches' => $totalMatches,
                'inspected_cards' => count($cards),
                'cards' => $cards,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::error("ISAPI fetchCards failed ({$url}): " . $e->getMessage());
            return ['status' => false, 'unsupported' => false, 'total_device_matches' => 0, 'inspected_cards' => 0, 'cards' => [], 'error' => 'ISAPI card inventory request failed.'];
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
                        'verify_method' => HikvisionPayloadParser::normalizeVerificationMethod($item['verifyMethod'] ?? $item['currentVerifyMode'] ?? null),
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
            $client = $this->buildHttpClient($door);
            $response = $client->post($url, $payload);
            $json = $response->json() ?? [];

            if ($response->successful()) {
                return $this->formatSuccessfulEventsResponse($json);
            }

            $error = $json['ResponseStatus'] ?? $json;
            $unsupported = strcasecmp((string) ($error['subStatusCode'] ?? ''), 'notSupport') === 0
                || strcasecmp((string) ($error['errorCode'] ?? ''), '0x40000001') === 0;
            $requiresJsonFormat = $response->status() === 400 && (
                str_contains($response->body(), '0x60000001')
                || strcasecmp((string) ($error['subStatusCode'] ?? ''), 'badXmlFormat') === 0
                || strcasecmp((string) ($error['errorMsg'] ?? ''), '0x50000001') === 0
            );

            if (!$unsupported && $requiresJsonFormat) {
                $response = $client->post($url . '?format=json', $payload);
                $json = $response->json() ?? [];
                if ($response->successful()) {
                    return $this->formatSuccessfulEventsResponse($json);
                }

                $error = $json['ResponseStatus'] ?? $json;
                $unsupported = strcasecmp((string) ($error['subStatusCode'] ?? ''), 'notSupport') === 0
                    || strcasecmp((string) ($error['errorCode'] ?? ''), '0x40000001') === 0;
            }

            return [
                'status' => false,
                'unsupported' => $unsupported,
                'statusCode' => $response->status(),
                'total' => 0,
                'events' => [],
                'data' => null,
                'error' => $unsupported ? 'Device event search unsupported.' : "HTTP {$response->status()}: Device event search failed.",
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

    private function formatSuccessfulEventsResponse(array $json): array
    {
        $container = $json['AcsEvent'] ?? $json;
        $rawList = $container['InfoList'] ?? ($json['events'] ?? []);
        if (isset($rawList['employeeNoString']) || isset($rawList['employeeNo'])) {
            $rawList = [$rawList];
        }
        $events = array_map(static fn (array $item): array => [
            'major' => $item['major'] ?? null,
            'minor' => $item['minor'] ?? null,
            'time' => $item['time'] ?? now()->toIso8601String(),
            'card_no' => $item['cardNo'] ?? ($item['card_no'] ?? null),
            'employee_no' => $item['employeeNoString'] ?? ($item['employeeNo'] ?? ($item['employee_no'] ?? null)),
            'name' => $item['name'] ?? null,
            'verify_method' => HikvisionPayloadParser::normalizeVerificationMethod($item['verifyMethod'] ?? $item['currentVerifyMode'] ?? null),
            'door_no' => $item['doorNo'] ?? ($item['door_no'] ?? null),
            'door_name' => $item['doorName'] ?? ($item['door_name'] ?? null),
            'access_status' => $item['accessStatus'] ?? ($item['access_status'] ?? 'Granted'),
        ], array_filter($rawList, 'is_array'));

        return [
            'status' => true,
            'statusCode' => 1,
            'total' => count($events),
            'total_device_matches' => (int) ($container['totalMatches'] ?? count($events)),
            'events' => $events,
            'error' => null,
        ];
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
            if (!empty($door->device_ip) && str_ends_with($door->device_ip, '.99')) {
                return [
                    'status' => false,
                    'statusCode' => 500,
                    'error' => "Simulated Device Offline ({$door->device_ip})",
                ];
            }
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

    /**
     * Build Hikvision ISAPI UserInfo payload for biometric/user profile provisioning.
     * Compatible with /ISAPI/AccessControl/UserInfo/SetUp?format=json
     */
    public function buildUserInfoPayload(Employee $employee, ?Door $door = null, array $options = []): array
    {
        $employeeNo = (string) ($employee->employee_id ?? $employee->nik);
        $doorNo = (int) ($door?->door_no ?? 1);

        return [
            'UserInfo' => [
                'employeeNo' => $employeeNo,
                'name' => (string) $employee->name,
                'userType' => $options['userType'] ?? 'normal',
                'closeDelay' => (int) ($options['closeDelay'] ?? 5),
                'userVerifyMode' => $options['userVerifyMode'] ?? 'cardOrFaceOrFp',
                'Valid' => [
                    'enable' => true,
                    'beginTime' => $options['beginTime'] ?? now()->subDay()->format('Y-m-d\TH:i:s'),
                    'endTime' => $options['endTime'] ?? now()->addYears(5)->format('Y-m-d\TH:i:s'),
                    'timeType' => 'local',
                ],
                'doorRight' => (string) ($options['doorRight'] ?? '1'),
                'RightPlan' => [
                    [
                        'doorNo' => $doorNo,
                        'planTemplateNo' => (string) ($options['planTemplateNo'] ?? '1'),
                    ],
                ],
                'maxSwipeTime' => (int) ($options['maxSwipeTime'] ?? 0),
                'normalScheduleNum' => (int) ($options['normalScheduleNum'] ?? 0),
            ],
        ];
    }

    /**
     * Push employee user profile to physical Hikvision terminal via /AccessControl/UserInfo/SetUp.
     */
    public function setUserInfo(Door $door, Employee $employee, array $options = []): array
    {
        $payload = $this->buildUserInfoPayload($employee, $door, $options);
        $employeeNo = $employee->employee_id ?? $employee->nik;

        // 1. Mock Mode: Direct Internal Controller Invocation
        if ($this->isMockMode()) {
            if (!empty($door->device_ip) && str_ends_with($door->device_ip, '.99')) {
                return [
                    'status' => false,
                    'statusCode' => 500,
                    'data' => null,
                    'error' => "Simulated Device Offline ({$door->device_ip})",
                ];
            }

            try {
                $request = Request::create('/api/mock/isapi/AccessControl/UserInfo/SetUp?format=json', 'PUT', $payload);
                $mockController = app(HikvisionMockController::class);
                $jsonResponse = $mockController->setupUserInfo($request);
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
                    'error' => $data['errorMsg'] ?? ($data['ResponseStatus']['errorMsg'] ?? 'User profile provisioning failed.'),
                ];
            } catch (\Throwable $e) {
                Log::error("ISAPI mock setUserInfo error: " . $e->getMessage());
                return [
                    'status' => false,
                    'statusCode' => 500,
                    'data' => null,
                    'error' => "ISAPI Mock Error: " . $e->getMessage(),
                ];
            }
        }

        // 2. Real Physical Device Mode
        $url = $this->buildUrl('/AccessControl/UserInfo/SetUp?format=json', $door);

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
                    'error' => $isOk ? null : ($json['errorMsg'] ?? ($json['ResponseStatus']['errorMsg'] ?? 'User profile provisioning failed.')),
                ];
            }

            return [
                'status' => false,
                'statusCode' => $response->status(),
                'data' => $json,
                'error' => $json['errorMsg'] ?? ($json['ResponseStatus']['errorMsg'] ?? ($json['statusString'] ?? "HTTP {$response->status()}: " . $response->body())),
            ];
        } catch (\Throwable $e) {
            Log::error("ISAPI setUserInfo failed for Employee {$employeeNo} ({$url}): " . $e->getMessage());
            return [
                'status' => false,
                'statusCode' => 500,
                'data' => null,
                'error' => "ISAPI Connection Error: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Orchestrate full biometric and access provisioning on target terminal:
     * 1. Check device connectivity (pingDevice)
     * 2. Provision User Profile (UserInfo SetUp)
     * 3. Sync RFID Card / Biometric Credential (CardInfo Record)
     * 4. Set Access Rights Plan (UserRightPlan SetUp)
     */
    public function provisionEmployeeAccess(Door $door, Employee $employee, array $options = []): array
    {
        // 1. Device online check
        if (!$this->pingDevice($door)) {
            return [
                'status' => false,
                'statusCode' => 503,
                'message' => "Perangkat pintu {$door->door_id} ({$door->device_ip}) tidak dapat dijangkau / offline",
                'error' => "Perangkat pintu {$door->door_id} offline",
                'steps' => [
                    'device_ping' => false,
                    'user_info' => null,
                    'card_sync' => null,
                    'access_right' => null,
                ],
            ];
        }

        // 2. Step 1: UserInfo SetUp
        $userInfoRes = $this->setUserInfo($door, $employee, $options);
        if (!$userInfoRes['status']) {
            return [
                'status' => false,
                'statusCode' => $userInfoRes['statusCode'] ?? 500,
                'message' => "Gagal provisioning UserInfo di terminal {$door->door_id}",
                'error' => $userInfoRes['error'] ?? 'UserInfo setup failed',
                'steps' => [
                    'device_ping' => true,
                    'user_info' => $userInfoRes,
                    'card_sync' => null,
                    'access_right' => null,
                ],
            ];
        }

        // 3. Step 2: CardInfo Record (if card_no present)
        $cardRes = null;
        if (!empty($employee->card_no)) {
            $cardRes = $this->syncCardUser(
                $employee->employee_id ?? $employee->nik,
                $employee->card_no,
                $employee->name,
                $door
            );

            if (!$cardRes['status']) {
                return [
                    'status' => false,
                    'statusCode' => $cardRes['statusCode'] ?? 500,
                    'message' => "Gagal provisioning kartu ({$employee->card_no}) di terminal {$door->door_id}",
                    'error' => $cardRes['error'] ?? 'Card synchronization failed',
                    'steps' => [
                        'device_ping' => true,
                        'user_info' => $userInfoRes,
                        'card_sync' => $cardRes,
                        'access_right' => null,
                    ],
                ];
            }
        }

        // 4. Step 3: UserRightPlan SetUp
        $rightRes = $this->setUserAccessRight($door, $employee);
        if (!$rightRes['status']) {
            return [
                'status' => false,
                'statusCode' => $rightRes['statusCode'] ?? 500,
                'message' => "Gagal provisioning rencana hak akses pintu {$door->door_id}",
                'error' => $rightRes['error'] ?? 'UserRightPlan setup failed',
                'steps' => [
                    'device_ping' => true,
                    'user_info' => $userInfoRes,
                    'card_sync' => $cardRes,
                    'access_right' => $rightRes,
                ],
            ];
        }

        return [
            'status' => true,
            'statusCode' => 200,
            'message' => "Berhasil mem-provisioning profil dan biometrik untuk {$employee->name} ke pintu {$door->door_id}",
            'error' => null,
            'steps' => [
                'device_ping' => true,
                'user_info' => $userInfoRes,
                'card_sync' => $cardRes,
                'access_right' => $rightRes,
            ],
        ];
    }
}
