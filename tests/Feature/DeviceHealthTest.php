<?php

namespace Tests\Feature;

use App\Domain\Hikvision\DeviceCapability;
use App\Domain\Hikvision\DeviceHealth;
use App\Models\Door;
use App\Services\DeviceHealthService;
use Tests\TestCase;

class DeviceHealthTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    /**
     * Test device health model creation.
     */
    public function test_device_health_can_be_created(): void
    {
        $health = new DeviceHealth('DS-K1T804AMF', 'GR3496472', 'v2.3.0');
        
        $this->assertEquals('DS-K1T804AMF', $health->model);
        $this->assertEquals('GR3496472', $health->serial);
        $this->assertEquals('v2.3.0', $health->firmware);
        $this->assertEquals('unknown', $health->status);
    }

    /**
     * Test capability model separates supported/installed/configured/healthy.
     */
    public function test_capability_distinguishes_states(): void
    {
        $cap = new DeviceCapability('Door Contact');
        
        // Supported by device, but not physically installed
        $cap->markSupported(true);
        $cap->installed = 'unknown'; // No physical inspection data
        $cap->markConfigured(false);
        
        $this->assertEquals('yes', $cap->supported);
        $this->assertEquals('unknown', $cap->installed);
        $this->assertEquals('no', $cap->configured);
        $this->assertFalse($cap->isReady());
    }

    /**
     * Test capability ready state requires all conditions.
     */
    public function test_capability_ready_requires_all_states(): void
    {
        $cap = new DeviceCapability('Card');
        $cap->markSupported(true)
            ->markInstalled(true)
            ->markConfigured(true)
            ->markHealthy('yes');
        
        $this->assertTrue($cap->isReady());
    }

    /**
     * Test device online state.
     */
    public function test_device_online_state(): void
    {
        $health = new DeviceHealth();
        $health->markOnline(45);
        
        $this->assertEquals('online', $health->status);
        $this->assertEquals(45, $health->latency_ms);
        $this->assertNotNull($health->last_seen_at);
        $this->assertNull($health->error_message);
    }

    /**
     * Test device offline state.
     */
    public function test_device_offline_state(): void
    {
        $health = new DeviceHealth();
        $health->markOffline('Connection timeout');
        
        $this->assertEquals('offline', $health->status);
        $this->assertEquals('Connection timeout', $health->error_message);
        $this->assertFalse($health->isReady());
    }

    /**
     * Test clock health classification.
     */
    public function test_clock_health_classification(): void
    {
        $health = new DeviceHealth();
        
        // Healthy: < 1 second
        $health->clock_offset_seconds = 0;
        $this->assertEquals('healthy', $health->classifyClockHealth());
        
        // Warning: < 1 minute
        $health->clock_offset_seconds = 30;
        $this->assertEquals('warning', $health->classifyClockHealth());
        
        // Critical: >= 1 minute
        $health->clock_offset_seconds = 300;
        $this->assertEquals('critical', $health->classifyClockHealth());
    }

    /**
     * Test device health serialization.
     */
    public function test_device_health_serializes_to_array(): void
    {
        $health = new DeviceHealth('DS-K1T804AMF', 'GR3496472', 'v2.3.0');
        $health->markOnline(50);
        $health->user_count = 96;
        $health->card_count = 82;
        
        $array = $health->toArray();
        
        $this->assertEquals('online', $array['status']);
        $this->assertEquals('DS-K1T804AMF', $array['model']);
        $this->assertEquals(96, $array['user_count']);
        $this->assertEquals(82, $array['card_count']);
        $this->assertIsArray($array['capabilities']);
    }

    /**
     * Test device health capabilities populate correctly.
     */
    public function test_device_health_has_capabilities(): void
    {
        $health = new DeviceHealth('DS-K1T804AMF', 'GR3496472', 'v2.3.0');
        
        // Manually populate for testing (service will do this)
        $isapi = new DeviceCapability('ISAPI');
        $isapi->markSupported(true)->markInstalled(true)->markConfigured(true)->markHealthy('yes');
        $health->capabilities['ISAPI'] = $isapi;
        
        $card = new DeviceCapability('Card');
        $card->markSupported(true)->markInstalled(true)->markConfigured(true)->markHealthy('yes');
        $health->capabilities['Card'] = $card;
        
        $this->assertCount(2, $health->capabilities);
        $this->assertTrue($health->capabilities['ISAPI']->isReady());
        $this->assertTrue($health->capabilities['Card']->isReady());
    }
}
