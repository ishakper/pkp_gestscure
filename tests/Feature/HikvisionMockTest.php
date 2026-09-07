<?php

namespace Tests\Feature;

use Tests\TestCase;

class HikvisionMockTest extends TestCase
{
    /**
     * Test GET /api/mock/isapi/System/status
     */
    public function test_device_status_returns_ok_and_online(): void
    {
        $response = $this->getJson('/api/mock/isapi/System/status');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'OK',
                'online' => true,
                'doorStatus' => 'closed',
            ])
            ->assertJsonStructure([
                'statusCode',
                'statusString',
                'DeviceStatus' => [
                    'status',
                    'online',
                    'currentDeviceTime',
                    'doorStatus',
                ],
                'status',
                'online',
                'currentDeviceTime',
                'doorStatus',
            ]);
    }

    /**
     * Test PUT /api/mock/isapi/AccessControl/CardInfo/Record validation failure (missing cardNo or employeeNo)
     */
    public function test_sync_card_fails_validation_when_parameters_are_empty(): void
    {
        // 1. Missing both cardNo & employeeNo
        $response = $this->putJson('/api/mock/isapi/AccessControl/CardInfo/Record', []);
        $response->assertStatus(400)
            ->assertJson([
                'statusCode' => 4,
                'statusString' => 'Invalid Operation',
                'subStatusCode' => 'badParameters',
            ])
            ->assertJsonStructure([
                'ResponseStatus' => [
                    'requestURL',
                    'statusCode',
                    'statusString',
                    'subStatusCode',
                    'errorMsg',
                ],
            ]);

        // 2. Missing employeeNo
        $response2 = $this->putJson('/api/mock/isapi/AccessControl/CardInfo/Record', [
            'cardNo' => 'CARD-123456',
        ]);
        $response2->assertStatus(400)
            ->assertJson([
                'statusCode' => 4,
                'subStatusCode' => 'badParameters',
            ]);

        // 3. Missing cardNo
        $response3 = $this->putJson('/api/mock/isapi/AccessControl/CardInfo/Record', [
            'employeeNo' => 'EMP-001',
        ]);
        $response3->assertStatus(400)
            ->assertJson([
                'statusCode' => 4,
                'subStatusCode' => 'badParameters',
            ]);
    }

    /**
     * Test PUT /api/mock/isapi/AccessControl/CardInfo/Record success with direct and nested CardInfo
     */
    public function test_sync_card_succeeds_with_valid_parameters(): void
    {
        // Direct payload
        $response = $this->putJson('/api/mock/isapi/AccessControl/CardInfo/Record', [
            'cardNo' => 'CARD-100234',
            'employeeNo' => 'EMP-001',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'statusCode' => 1,
                'statusString' => 'OK',
                'cardNo' => 'CARD-100234',
                'employeeNo' => 'EMP-001',
                'ResponseStatus' => [
                    'statusCode' => 1,
                    'statusString' => 'OK',
                ],
            ]);

        // Nested ISAPI CardInfo payload
        $nestedResponse = $this->putJson('/api/mock/isapi/AccessControl/CardInfo/Record', [
            'CardInfo' => [
                'cardNo' => 'CARD-889900',
                'employeeNo' => 'EMP-002',
            ],
        ]);

        $nestedResponse->assertStatus(200)
            ->assertJson([
                'statusCode' => 1,
                'statusString' => 'OK',
                'cardNo' => 'CARD-889900',
                'employeeNo' => 'EMP-002',
            ]);
    }

    /**
     * Test POST /api/mock/isapi/AccessControl/AcsEvent returns card and fingerprint access logs
     */
    public function test_fetch_access_logs_returns_sample_history(): void
    {
        $response = $this->postJson('/api/mock/isapi/AccessControl/AcsEvent', [
            'AcsEventCond' => [
                'searchID' => '1',
                'searchResultPosition' => 0,
                'maxResults' => 10,
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'statusCode' => 1,
                'statusString' => 'OK',
            ])
            ->assertJsonStructure([
                'AcsEvent' => [
                    'searchID',
                    'responseStatusStrg',
                    'numOfMatches',
                    'totalMatches',
                    'InfoList',
                ],
                'events',
            ]);

        $events = $response->json('events');
        $this->assertNotEmpty($events);

        // Verify verification methods present (Card and Fingerprint)
        $verifyMethods = array_column($events, 'verifyMethod');
        $this->assertContains('Card', $verifyMethods);
        $this->assertContains('Fingerprint', $verifyMethods);
    }
}
