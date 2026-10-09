# Hardcoded Door/1 Fix Design — Yazied Integration Blocker

**Issue:** `HikvisionIsapiService::remoteControlDoor()` line 389 uses hardcoded `/door/1` path.

**Impact:** All remote unlock commands target terminal 1 regardless of requested door.

**Fix Design:**

## Current Code (Problematic)

```php
// app/Services/HikvisionIsapiService.php line 389
$url = $this->buildUrl('/AccessControl/RemoteControl/door/1', $door);
```

## Recommended Fix

### Option A: Door Model Channel Field (Preferred)

Add `device_channel` column to doors table:

```php
// database/migrations/XXXX_add_device_channel_to_doors.php
Schema::table('doors', function (Blueprint $table) {
    $table->integer('device_channel')->default(1)->after('device_ip');
    $table->index('device_channel');
});

// app/Models/Door.php
protected $fillable = [..., 'device_channel', ...];

// app/Services/HikvisionIsapiService.php
public function remoteControlDoor(Door $door, string $command = 'open'): array
{
    $channel = $door->device_channel ?? 1; // fallback to 1 if not set
    $url = $this->buildUrl("/AccessControl/RemoteControl/door/{$channel}", $door);
    // ... rest of implementation
}
```

### Option B: Configuration Mapping (Backup)

```php
// config/hikvision.php
'door_channel_map' => [
    'DOOR-A' => 1,
    'DOOR-B' => 2,  // multiple terminals per building
    'DOOR-C' => 3,
    'DOOR-D' => 4,
],

// app/Services/HikvisionIsapiService.php
public function remoteControlDoor(Door $door, string $command = 'open'): array
{
    $channels = config('hikvision.door_channel_map');
    $channel = $channels[$door->door_id] ?? $door->device_channel ?? 1;
    $url = $this->buildUrl("/AccessControl/RemoteControl/door/{$channel}", $door);
    // ... rest of implementation
}
```

## Implementation Checklist

- [ ] Choose Option A (model field) or Option B (config mapping)
- [ ] Create migration (if Option A)
- [ ] Update Door model fillable array
- [ ] Modify `HikvisionIsapiService::remoteControlDoor()` method
- [ ] Update seeder/factory with device_channel values
- [ ] Create isolated unit test (mock ISAPI)
- [ ] Verify correct channel in ISAPI URL for each door
- [ ] Document in readme: "Door terminal channels configurable via device_channel field"
- [ ] Test in test environment ONLY (never on Door-B)
- [ ] Security review + approval

## Testing Strategy

**Required (Test Environment Only):**

```php
// tests/Unit/HikvisionIsapiServiceTest.php
public function test_remoteControlDoor_uses_correct_channel()
{
    // Mock HikvisionIsapiService
    $mock = Mockery::mock(HikvisionIsapiService::class);
    
    $doorA = Door::factory()->create(['door_id' => 'DOOR-A', 'device_channel' => 1]);
    $doorB = Door::factory()->create(['door_id' => 'DOOR-B', 'device_channel' => 2]);
    
    // Verify correct URL built for each door
    // $this->assertUrlContains('/door/1', $doorA);
    // $this->assertUrlContains('/door/2', $doorB);
}
```

**Never test on real Door-B or send actual unlock commands.**

## Security Considerations

- ✓ Channel comes from trusted DB (not user input)
- ✓ Numeric validation already in place (integers)
- ✓ Fallback to sensible default (1) if not set
- ✓ No bypass possible from frontend

## Timeline

- Design approval: Required before integration branch
- Implementation: ~1-2 hours
- Testing: ~30 minutes (mocked)
- Code review: Required before merge to stable
- Deployment: After full approvals

## Blocks Integration Until

- [ ] Decision: Option A or B (or alternative) approved
- [ ] Design documented
- [ ] Implementer assigned
- [ ] Timeline confirmed
