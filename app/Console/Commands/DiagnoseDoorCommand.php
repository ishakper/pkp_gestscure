<?php

namespace App\Console\Commands;

use App\Models\Door;
use App\Services\HikvisionIsapiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DiagnoseDoorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'door:diagnose {door_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Comprehensively diagnose a door terminal (Network, ISAPI Auth, Lockout, Webhook)';

    /**
     * Execute the console command.
     */
    public function handle(HikvisionIsapiService $isapiService)
    {
        $doorId = $this->argument('door_id');
        $door = Door::where('door_id', $doorId)->orWhere('id', $doorId)->first();

        if (!$door) {
            $this->error("Door [{$doorId}] not found in database.");
            return 1;
        }

        $this->info("Starting Diagnostics for Door: {$door->door_name} ({$door->door_id})");
        
        $creds = $isapiService->getDeviceCredentials($door);
        $hasUser = !empty($creds['username']);
        $hasPass = !empty($creds['password']);
        $hasIp = !empty($door->device_ip);

        $this->line("USERNAME_CONFIGURED = " . ($hasUser ? "YES" : "NO"));
        $this->line("PASSWORD_CONFIGURED = " . ($hasPass ? "YES" : "NO"));
        $this->line("IP_CONFIGURED       = " . ($hasIp ? "YES" : "NO"));
        $this->line("MOCK_MODE           = " . ($isapiService->isMockMode() ? "TRUE" : "REAL"));
        
        $this->line("Model               : {$door->device_model}");
        $this->line(str_repeat('-', 50));

        // 1. Network Ping
        $this->info("1. Network Reachability (Ping)");
        $ip = $door->device_ip;
        
        // Quick socket check to port 80 instead of ICMP ping for better reliability in some networks
        $fp = @fsockopen($ip, 80, $errno, $errstr, 2);
        if ($fp) {
            $this->line("   [PASS] TCP Port 80 is open and reachable.");
            fclose($fp);
        } else {
            $this->error("   [FAIL] TCP Port 80 is unreachable ($errstr). Check network connection or IP address.");
            return 1;
        }

        // 2. ISAPI Auth & Lockout Check
        $this->info("\n2. ISAPI Authentication & Lockout Status");
        $baseUrl = "http://{$door->device_ip}/ISAPI";
        
        // We will make a raw HTTP request without auth first to see if we get a 401 Challenge or a timeout
        $response = Http::timeout(3)->get("{$baseUrl}/System/deviceInfo");
        if ($response->status() === 401) {
            $this->line("   [INFO] Received HTTP 401 Challenge (Expected for unauthenticated request).");
        } elseif ($response->successful()) {
            $this->line("   [WARN] Received HTTP 200 without authentication. Device might not have Digest Auth enabled!");
        } else {
            $this->error("   [FAIL] Unexpected response from ISAPI: HTTP {$response->status()}");
        }

        // Now we make the authenticated request
        $this->line("   Attempting Digest Authentication...");
        $creds = $isapiService->getDeviceCredentials($door);
        $authResponse = Http::withDigestAuth($creds['username'], $creds['password'])
                            ->timeout(5)
                            ->get("{$baseUrl}/System/deviceInfo");

        if ($authResponse->successful()) {
            $this->line("   [PASS] ISAPI Digest Authentication successful (HTTP 200).");
            
            // Check lockout xml
            if (str_contains($authResponse->body(), '<lockStatus>lock</lockStatus>')) {
                $this->error("   [FAIL] DEVICE IS LOCKED OUT! lockStatus = lock. You must wait for the lockout to expire.");
            } else {
                $this->line("   [PASS] Device is not locked out.");
            }
        } elseif ($authResponse->status() === 401) {
            $this->error("   [FAIL] ISAPI Digest Authentication FAILED (HTTP 401).");
            $this->error("          Possible causes:");
            $this->error("          1. Incorrect password.");
            $this->error("          2. Device is temporarily locked out due to too many failed attempts.");
            $this->error("             (If locked out, even the correct password will return 401 for a while).");
        } else {
            $this->error("   [FAIL] ISAPI Request failed: HTTP {$authResponse->status()}");
        }

        // 3. Webhook Configuration Check
        $this->info("\n3. Webhook Registration Check");
        if ($authResponse->successful()) {
            $webhookResponse = Http::withDigestAuth($creds['username'], $creds['password'])
                                ->timeout(5)
                                ->get("{$baseUrl}/Event/notification/httpHosts");

            if ($webhookResponse->successful()) {
                $this->line("   [PASS] Retrieved httpHosts successfully.");
                $body = $webhookResponse->body();
                
                // We know from previous diagnostics that httpHosts/2 is used for standard HTTP.
                // We will check if SecureGate's URL is present.
                $appUrl = env('APP_URL');
                $appHost = parse_url($appUrl, PHP_URL_HOST);
                
                if ($appHost && str_contains($body, $appHost)) {
                    $this->line("   [PASS] Webhook is registered for host: {$appHost}");
                } else {
                    $this->error("   [WARN] Webhook host '{$appHost}' not found in httpHosts!");
                    $this->line("          Consider running: php artisan hikvision:register-webhook");
                }
            } else {
                $this->error("   [FAIL] Failed to retrieve httpHosts (HTTP {$webhookResponse->status()})");
            }
        } else {
            $this->line("   [SKIP] Cannot check Webhook registration due to ISAPI Auth failure.");
        }

        $this->info("\nDiagnosis Complete.");
        return 0;
    }
}
