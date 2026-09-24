<?php

namespace Tests\Feature;

use App\Models\Door;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegisterHikvisionWebhookCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'name' => 'Pintu Gedung B',
            'door_name' => 'Pintu Gedung B',
            'location' => 'Gedung B (IT & Infra)',
            'device_ip' => '192.168.90.15',
            'ip_address' => '192.168.90.15',
            'is_active' => true,
            'controller_type' => 'Hikvision DS-K1T804AMF',
        ]);
    }

    public function test_register_webhook_command_mock_mode(): void
    {
        config(['services.hikvision.mock_mode' => true]);

        $this->artisan('door:register-webhook DOOR-B')
            ->expectsOutputToContain('HIKVISION ISAPI - HTTP HOST WEBHOOK LISTENER REGISTRATION')
            ->expectsOutputToContain('192.168.90.15')
            ->expectsOutputToContain('192.168.90.64')
            ->expectsOutputToContain('MOCK MODE ACTIVE')
            ->assertExitCode(0);
    }

    public function test_register_webhook_command_real_mode_success(): void
    {
        Http::fake([
            'http://192.168.90.15/ISAPI/Event/notification/httpHosts/2' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><ResponseStatus version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema"><requestURL>/ISAPI/Event/notification/httpHosts/2</requestURL><statusCode>1</statusCode><statusString>OK</statusString><subStatusCode>ok</subStatusCode></ResponseStatus>',
                200,
                ['Content-Type' => 'application/xml']
            ),
        ]);

        $this->artisan('door:register-webhook DOOR-B --real')
            ->expectsOutputToContain('HTTP Response Status : 200')
            ->expectsOutputToContain('statusString         : OK')
            ->expectsOutputToContain('[SUCCESS]')
            ->assertExitCode(0);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $body = $request->body();
            return str_contains($body, '<id>2</id>')
                && str_contains($body, '<parameterFormatType>XML</parameterFormatType>')
                && (str_contains($body, '<url>/api/v1/isapi/event-notification?door_id=DOOR-B</url>')
                    || str_contains($body, '<url>/api/v1/isapi/event-notification</url>'))
                && str_contains($body, '<ipAddress>192.168.90.64</ipAddress>');
        });
    }

    public function test_register_webhook_rejects_device_error_even_with_http_200(): void
    {
        Http::fake(['*' => Http::response('<ResponseStatus><statusString>Invalid XML Content</statusString></ResponseStatus>', 200)]);

        $this->artisan('door:register-webhook DOOR-B --real')->assertExitCode(1);
    }

    public function test_register_webhook_rejects_http_error_even_with_ok_body(): void
    {
        Http::fake(['*' => Http::response('<ResponseStatus><statusString>OK</statusString></ResponseStatus>', 500)]);

        $this->artisan('door:register-webhook DOOR-B --real')->assertExitCode(1);
    }
}
