<?php

namespace Tests\Unit;

use Tests\TestCase;

class IpCidrValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.hikvision.allowed_cidrs' => ['192.168.90.0/24', '172.25.0.0/16']]);
    }
    /** @test */
    public function test_valid_ip_in_allowlist_cidr()
    {
        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('192.168.90.100');
        $this->assertTrue($result['allowed'], 'IP in default allowlist should be allowed');
    }

    /** @test */
    public function test_valid_ip_in_second_allowlist_cidr()
    {
        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('172.25.10.50');
        $this->assertTrue($result['allowed'], 'IP in second allowlist CIDR should be allowed');
    }

    /** @test */
    public function test_ip_outside_allowlist()
    {
        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('10.0.0.1');
        $this->assertFalse($result['allowed'], 'IP outside allowlist should be rejected');
        $this->assertStringContainsString('not in allowlist', $result['reason']);
    }

    /** @test */
    public function test_reject_loopback_127_0_0_1()
    {
        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('127.0.0.1');
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Loopback', $result['reason']);
    }

    /** @test */
    public function test_reject_loopback_range()
    {
        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('127.255.255.255');
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Loopback', $result['reason']);
    }

    /** @test */
    public function test_reject_link_local()
    {
        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('169.254.100.1');
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Link-local', $result['reason']);
    }

    /** @test */
    public function test_reject_multicast()
    {
        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('224.0.0.1');
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Multicast', $result['reason']);
    }

    /** @test */
    public function test_reject_broadcast()
    {
        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('255.255.255.255');
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Broadcast', $result['reason']);
    }

    /** @test */
    public function test_reject_metadata_endpoint()
    {
        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('169.254.169.254');
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('metadata', $result['reason']);
    }

    /** @test */
    public function test_reject_unspecified()
    {
        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('0.0.0.0');
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Unspecified', $result['reason']);
    }

    /** @test */
    public function test_reject_invalid_ip_format()
    {
        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('not-an-ip');
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Invalid', $result['reason']);
    }

    /** @test */
    public function test_cidr_single_ip_notation()
    {
        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('192.168.90.15');
        $this->assertTrue($result['allowed']);
    }

    /** @test */
    public function test_cidr_32_notation()
    {
        // 192.168.90.0/24 includes .100
        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('192.168.90.100');
        $this->assertTrue($result['allowed']);
    }

    /** @test */
    public function test_cidr_edge_cases()
    {
        // Test edge of CIDR block
        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('192.168.90.1');
        $this->assertTrue($result['allowed'], '192.168.90.1 should be in 192.168.90.0/24');

        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('192.168.90.254');
        $this->assertTrue($result['allowed'], '192.168.90.254 should be in 192.168.90.0/24');

        $result = \App\Services\IpCidrValidator::validateManualDeviceIp('192.168.91.1');
        $this->assertFalse($result['allowed'], '192.168.91.1 should NOT be in 192.168.90.0/24');
    }
}
