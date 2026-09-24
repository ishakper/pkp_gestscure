<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Runs the real building-scope logic from public/js/dashboard.js in Node against a mocked API
 * (tests/js/dashboard-scope.harness.cjs). Static string checks cannot prove that the dashboard
 * never invents numbers; executing the code can.
 */
class DashboardBuildingScopeBehaviourTest extends TestCase
{
    private static ?array $result = null;

    private function run_harness(): array
    {
        if (self::$result !== null) {
            return self::$result;
        }

        $node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('Node.js is not available to execute the dashboard scope harness.');
        }

        $script = base_path('tests/js/dashboard-scope.harness.cjs');
        $output = shell_exec('node ' . escapeshellarg($script) . ' 2>&1');
        $decoded = json_decode((string) $output, true);
        $this->assertIsArray($decoded, 'Harness did not return JSON: ' . $output);

        return self::$result = $decoded;
    }

    public function test_legacy_backend_is_detected_once_and_building_id_is_not_sent_again(): void
    {
        $r = $this->run_harness();

        $this->assertSame(['legacy' => true], $r['legacyFirst']);
        $this->assertSame(1, $r['legacyFirstCalls'], 'the probe is a single request');
        $this->assertSame(['legacy' => true], $r['legacySecond']);
        $this->assertFalse($r['legacySecondSentBuildingId']);
        $this->assertTrue($r['legacyFlag'], 'legacy mode must be surfaced to the user');
    }

    public function test_a_backend_that_echoes_the_building_is_used_directly(): void
    {
        $r = $this->run_harness();

        $this->assertTrue($r['honouredHasRes']);
        $this->assertSame('/admin/dashboard-metrics?building_id=2', $r['honouredUrl']);
        $this->assertTrue($r['honouredSupport']);
        $this->assertFalse($r['honouredLegacyFlag']);
        $this->assertSame('/admin/dashboard-metrics', $r['unscopedUrl'], 'Semua Gedung sends no building_id');
    }

    public function test_an_echo_for_a_different_building_is_not_trusted(): void
    {
        $this->assertTrue($this->run_harness()['wrongEcho']);
    }

    public function test_legacy_fallback_never_invents_numbers(): void
    {
        $r = $this->run_harness();

        // Not derivable exactly from existing endpoints -> null, rendered as "—".
        $this->assertNull($r['fallbackKnown']['registeredCredentials']);
        $this->assertNull($r['fallbackStatusUnknown']['activeEmployees'], 'unknown status is not zero and not the total');
        $this->assertSame(4, $r['fallbackKnown']['activeEmployees']);

        // Exact: only the building's own doors (DOOR-B 7 + DOOR-C 3, never DOOR-A's 99).
        $this->assertSame(10, $r['fallbackKnown']['deniedLogs']);
        $this->assertSame(1, $r['fallbackKnown']['activeDoors']);
        $this->assertSame(2, $r['fallbackKnown']['totalDoors']);
        $this->assertSame(5, $r['fallbackKnown']['totalUsers']);
    }

    public function test_building_admin_never_sends_or_remembers_a_filter(): void
    {
        $r = $this->run_harness();

        $this->assertSame('', $r['buildingAdminFilter']);
        $this->assertSame('', $r['buildingAdminAfterChange']);
    }
}
