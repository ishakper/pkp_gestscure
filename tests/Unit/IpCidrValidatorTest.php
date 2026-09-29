<?php

namespace Tests\Unit;

use App\Services\IpCidrValidator;
use Tests\TestCase;

class IpCidrValidatorTest extends TestCase
{
    public function test_allows_exact_address_assigned_to_door(): void
    {
        config(['services.hikvision.allowed_cidrs' => []]);

        $this->assertTrue(
            IpCidrValidator::validateManualDeviceIp('192.168.90.15', ['192.168.90.15'])['allowed']
        );
    }

    public function test_allows_configured_device_subnet_and_denies_other_private_subnets(): void
    {
        config(['services.hikvision.allowed_cidrs' => ['192.168.90.0/24']]);

        $this->assertTrue(IpCidrValidator::validateManualDeviceIp('192.168.90.44')['allowed']);
        $this->assertFalse(IpCidrValidator::validateManualDeviceIp('10.0.0.1')['allowed']);
    }

    /** @dataProvider dangerousAddressProvider */
    public function test_rejects_dangerous_or_unsupported_addresses(string $ip): void
    {
        config(['services.hikvision.allowed_cidrs' => ['0.0.0.0/0']]);

        $this->assertFalse(IpCidrValidator::validateManualDeviceIp($ip) ['allowed']);
    }

    public static function dangerousAddressProvider(): array
    {
        return [
            ['127.0.0.1'],
            ['169.254.169.254'],
            ['224.0.0.1'],
            ['255.255.255.255'],
            ['0.0.0.0'],
            ['::1'],
        ];
    }

    public function test_empty_allowlist_fails_closed(): void
    {
        config(['services.hikvision.allowed_cidrs' => []]);

        $result = IpCidrValidator::validateManualDeviceIp('192.168.90.99');

        $this->assertFalse($result['allowed']);
        $this->assertSame('IP is not authorized for this door', $result['reason']);
    }
}
