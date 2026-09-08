<?php

namespace App\Console\Commands;

use App\Models\Door;
use App\Services\HikvisionIsapiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RegisterHikvisionWebhookCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'door:register-webhook 
                            {door_id=DOOR-B : Kode identitas terminal pintu}
                            {--ip=10.10.8.124 : Alamat IP host server penerima webhook}
                            {--port=8000 : Port server penerima webhook}
                            {--path=/api/v1/isapi/event-notification : URL endpoint webhook}
                            {--user=admin : Username Digest Auth}
                            {--password=PKP12345678 : Password Digest Auth}
                            {--real : Paksa request HTTP nyata ke terminal melewati mode mock}';

    /**
     * The console command description.
     */
    protected $description = 'Daftarkan listener HTTP Host Webhook ke terminal fisik Hikvision ISAPI';

    /**
     * Execute the console command.
     */
    public function handle(HikvisionIsapiService $isapiService): int
    {
        $this->info('================================================================================');
        $this->info('  HIKVISION ISAPI - HTTP HOST WEBHOOK LISTENER REGISTRATION');
        $this->info('================================================================================');

        $doorId = strtoupper($this->argument('door_id'));
        $door = Door::where('door_id', $doorId)->orWhere('id', $doorId)->first();

        $deviceIp = $door && !empty($door->device_ip) 
            ? $door->device_ip 
            : ($doorId === 'DOOR-B' ? env('DOOR_B_IP', '192.168.90.15') : '192.168.90.15');

        $listenerIp = $this->option('ip') ?: '10.10.8.124';
        $listenerPort = (int) ($this->option('port') ?: 8000);
        $listenerPath = $this->option('path') ?: '/api/v1/isapi/event-notification';
        $forceReal = $this->option('real');

        $creds = $isapiService->getDeviceCredentials($door);
        $username = $this->option('user') ?: ($creds['username'] ?? 'admin');
        $password = $this->option('password') ?: ($creds['password'] ?? 'PKP12345678');

        $endpointUrl = "http://{$deviceIp}/ISAPI/Event/notification/httpHosts";

        $xmlPayload = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<HttpHostNotificationList version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema">
  <HttpHostNotification version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema">
    <id>1</id>
    <url>{$listenerPath}</url>
    <protocolType>HTTP</protocolType>
    <parameterFormatType>xml</parameterFormatType>
    <addressingFormatType>ipaddress</addressingFormatType>
    <ipAddress>{$listenerIp}</ipAddress>
    <portNo>{$listenerPort}</portNo>
    <httpAuthenticationMethod>none</httpAuthenticationMethod>
  </HttpHostNotification>
  <HttpHostNotification version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema">
    <id>2</id>
    <url></url>
    <protocolType>HTTP</protocolType>
    <parameterFormatType>xml</parameterFormatType>
    <addressingFormatType>ipaddress</addressingFormatType>
    <portNo>0</portNo>
    <httpAuthenticationMethod>none</httpAuthenticationMethod>
  </HttpHostNotification>
</HttpHostNotificationList>
XML;

        $this->comment("Target Door          : {$doorId} (" . ($door->name ?? 'Access Door') . ")");
        $this->comment("Device IP            : {$deviceIp}");
        $this->comment("ISAPI Endpoint       : {$endpointUrl}");
        $this->comment("Destination Listener : http://{$listenerIp}:{$listenerPort}{$listenerPath}");
        $this->comment("Auth Method          : Digest (User: {$username})");
        $this->line('');

        // 1. Mock Mode Simulation
        if (!$forceReal && $isapiService->isMockMode()) {
            $this->warn('[MOCK MODE ACTIVE] Simulasi pendaftaran HTTP Host Webhook tanpa koneksi fisik.');
            $this->info("HTTP Response Status : 200");
            $this->info("statusString         : OK");
            $this->line("Response Body        :\n<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<ResponseStatus version=\"2.0\" xmlns=\"http://www.isapi.org/ver20/XMLSchema\">\n  <requestURL>/ISAPI/Event/notification/httpHosts</requestURL>\n  <statusCode>1</statusCode>\n  <statusString>OK</statusString>\n  <subStatusCode>ok</subStatusCode>\n</ResponseStatus>");
            $this->info("\n[SUCCESS] Listener HTTP Host Webhook berhasil didaftarkan (Simulated) ke {$doorId} ({$deviceIp})!");
            return Command::SUCCESS;
        }

        // 2. Real Physical Hardware Communication
        try {
            $this->info("Mengirimkan HTTP PUT ke terminal fisik {$deviceIp}...");
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->withDigestAuth($username, $password)
                ->withHeaders([
                    'Content-Type' => 'application/xml',
                    'Accept' => 'application/xml, text/xml, */*',
                ])
                ->withBody($xmlPayload, 'application/xml')
                ->put($endpointUrl);

            $statusCode = $response->status();
            $body = trim($response->body());

            // Extract statusString from response XML or JSON
            $statusString = 'Unknown';
            if (preg_match('/<statusString>(.*?)<\/statusString>/i', $body, $matches)) {
                $statusString = $matches[1];
            } elseif ($response->json('statusString')) {
                $statusString = $response->json('statusString');
            }

            $this->info("HTTP Response Status : {$statusCode}");
            $this->info("statusString         : {$statusString}");
            $this->line("Response Body        :\n{$body}");

            if (in_array($statusCode, [200, 204], true) || strcasecmp($statusString, 'OK') === 0) {
                $this->info("\n[SUCCESS] Listener HTTP Host Webhook berhasil didaftarkan ke terminal {$doorId} ({$deviceIp})!");
                return Command::SUCCESS;
            }

            $this->error("\n[ERROR] Terminal menolak konfigurasi HTTP Host Webhook (HTTP {$statusCode}).");
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->error("\n[EXCEPTION] Gagal berkomunikasi dengan terminal {$deviceIp}: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
