<?php

namespace App\Console\Commands;

use App\Models\Door;
use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestWebhookSmokeCommand extends Command
{
    protected $signature = 'doors:smoke-webhook {door_id=DOOR-B : Door identifier to test}';
    protected $description = 'Verify authenticated and IP-bound physical ISAPI webhook security';

    public function handle(): int
    {
        $doorId = strtoupper((string) $this->argument('door_id'));
        $door = Door::where('door_id', $doorId)->first();
        $employee = Employee::first();
        $secret = config("services.doors.{$doorId}.webhook_secret");

        if (!$door || !$employee || empty($door->device_ip) || empty($secret)) {
            $this->error('Door, employee, device IP, or configured webhook secret missing.');
            return Command::FAILURE;
        }

        $otherDoorIp = Door::where('door_id', '!=', $doorId)
            ->whereNotNull('device_ip')
            ->where('device_ip', '!=', '')
            ->value('device_ip') ?? '203.0.113.5';
        $wrongIp = '203.0.113.5';
        $payload = [
            'door_id' => $doorId,
            'user' => $employee->nik ?? $employee->employee_id,
            'verify_method' => 'Fingerprint',
            'access_status' => 'Granted',
            'timestamp' => now()->toIso8601String(),
        ];

        $cases = [
            ['Valid configured secret', $door->device_ip, $secret, $doorId, 200],
            ['Native physical IP-bound', $door->device_ip, null, $doorId, 200],
            ['Invalid credential cannot downgrade', $door->device_ip, 'invalid_hacker_token', $doorId, 403],
            ['Valid secret from wrong IP', $wrongIp, $secret, $doorId, 403],
            ['Door claimed from another door IP', $otherDoorIp, null, $doorId, 403],
            ['Unknown door', $door->device_ip, null, 'UNKNOWN-DOOR', 403],
        ];

        $results = [];
        $passed = true;
        foreach ($cases as [$name, $ip, $caseSecret, $claimedDoorId, $expected]) {
            $server = ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json'];
            if ($caseSecret !== null) {
                $server['HTTP_X_DEVICE_SECRET'] = $caseSecret;
            }

            $request = Request::create(
                "/api/v1/isapi/event-notification?door_id={$claimedDoorId}",
                'POST',
                array_replace($payload, ['door_id' => $claimedDoorId]),
                [],
                [],
                $server
            );
            $actual = app()->handle($request)->getStatusCode();
            $casePassed = $actual === $expected;
            $passed = $passed && $casePassed;
            $results[] = [$name, $ip, $expected, $actual, $casePassed ? 'PASSED' : 'FAILED'];
        }

        $this->table(['Case', 'Direct IP', 'Expected', 'Actual', 'Result'], $results);
        if (!$passed) {
            $this->error('Smoke test failed.');
            return Command::FAILURE;
        }

        $this->info('Smoke Test Finished: All assertions verified.');
        return Command::SUCCESS;
    }
}
