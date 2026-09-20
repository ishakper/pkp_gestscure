<?php

namespace Tests\Feature;

use App\Models\Door;
use App\Services\HikvisionIsapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DoorEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private function createBaseDoor(array $attributes = []): Door
    {
        return Door::create(array_merge([
            'door_id' => 'DOOR-ENC-01',
            'name' => 'Secure Server Room Door',
            'location' => 'Gedung Server Lt 1',
            'device_ip' => '192.168.90.100',
            'status' => 'online',
            'connection_status' => 'online',
        ], $attributes));
    }

    /**
     * Case 1: Setting isapi_password stores ciphertext in database, not plaintext.
     */
    public function test_setting_isapi_password_stores_ciphertext_in_database(): void
    {
        $plain = 'HikvisionSecret2026!';
        $door = $this->createBaseDoor([
            'isapi_password' => $plain,
        ]);

        $rawInDb = DB::table('doors')->where('id', $door->id)->value('isapi_password');

        $this->assertNotNull($rawInDb);
        $this->assertNotEquals($plain, $rawInDb);
        $this->assertEquals($plain, Crypt::decryptString($rawInDb));
    }

    /**
     * Case 2: Reading $door->isapi_password returns original plaintext.
     */
    public function test_reading_isapi_password_returns_original_plaintext(): void
    {
        $plain = 'HikvisionSecret2026!';
        $door = $this->createBaseDoor([
            'isapi_password' => $plain,
        ]);

        $fresh = Door::findOrFail($door->id);
        $this->assertSame($plain, $fresh->isapi_password);
    }

    /**
     * Case 3: Updating isapi_password re-encrypts correctly.
     */
    public function test_updating_isapi_password_re_encrypts_correctly(): void
    {
        $door = $this->createBaseDoor([
            'isapi_password' => 'InitialPassword123!',
        ]);

        $updatedPlain = 'RotatedPassword456@';
        $door->update(['isapi_password' => $updatedPlain]);

        $rawInDb = DB::table('doors')->where('id', $door->id)->value('isapi_password');
        $this->assertNotEquals($updatedPlain, $rawInDb);
        $this->assertEquals($updatedPlain, Crypt::decryptString($rawInDb));

        $fresh = Door::findOrFail($door->id);
        $this->assertSame($updatedPlain, $fresh->isapi_password);
    }

    /**
     * Case 4: Null isapi_password handled cleanly.
     */
    public function test_null_isapi_password_handled_cleanly(): void
    {
        $door = $this->createBaseDoor([
            'isapi_password' => null,
        ]);

        $rawInDb = DB::table('doors')->where('id', $door->id)->value('isapi_password');
        $this->assertNull($rawInDb);

        $fresh = Door::findOrFail($door->id);
        $this->assertNull($fresh->isapi_password);
    }

    /**
     * Case 5: HikvisionIsapiService::getDeviceCredentials receives DECRYPTED plaintext, not ciphertext.
     */
    public function test_hikvision_isapi_service_receives_decrypted_plaintext(): void
    {
        $plain = 'CameraGate#804AMF';
        $door = $this->createBaseDoor([
            'isapi_username' => 'admin',
            'isapi_password' => $plain,
        ]);

        $service = app(HikvisionIsapiService::class);
        $credentials = $service->getDeviceCredentials($door);

        $this->assertSame('admin', $credentials['username']);
        $this->assertSame($plain, $credentials['password']);
        $this->assertNotEquals(
            DB::table('doors')->where('id', $door->id)->value('isapi_password'),
            $credentials['password']
        );
    }

    /**
     * Case 6: Legacy Crypt::encryptString ciphertext in DB decodes seamlessly via model cast.
     */
    public function test_legacy_ciphertext_in_database_is_decrypted_by_model(): void
    {
        $plain = 'LegacySecretKey999!';
        $ciphertext = Crypt::encryptString($plain);

        $id = DB::table('doors')->insertGetId([
            'door_id' => 'DOOR-LEGACY',
            'name' => 'Legacy Door',
            'device_ip' => '192.168.90.105',
            'status' => 'online',
            'connection_status' => 'online',
            'isapi_username' => 'admin',
            'isapi_password' => $ciphertext,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $door = Door::findOrFail($id);
        $this->assertSame($plain, $door->isapi_password);
    }
}
