# Remote Unlock Reason Backend Hardening — Required Implementation

**Decision:** Reason must be required, validated, and persisted in audit log.

**Current State:** UI requires reason, backend accepts but does NOT validate or log it.

**Required Change:**

## AdminDoorController::openDoor()

Add reason validation:

```php
public function openDoor(Request $request, $door_id, HikvisionIsapiService $isapiService)
{
    $door = Door::where('door_id', $door_id)->orWhere('id', $door_id)->firstOrFail();

    if ($request->user() && method_exists($this, 'authorize')) {
        $this->authorize('open', $door);
    }

    // ADD: Validate reason
    $validated = $request->validate([
        'reason' => 'required|string|min:10|max:500',
    ]);
    $reason = trim($validated['reason']);
    
    // Reject whitespace-only
    if (blank($reason)) {
        return response()->json([
            'status' => 'error',
            'message' => 'Reason cannot be empty or whitespace-only.',
        ], 422);
    }

    if ($door->connection_status !== 'online' || $door->health_status === 'auth_error') {
        return response()->json([
            'status' => 'error',
            'code' => 409,
            'message' => 'Remote unlock diblokir: terminal belum terverifikasi online.',
        ], 409);
    }

    $result = $isapiService->remoteControlDoor($door, 'open');

    if (!($result['status'] ?? false)) {
        return response()->json([
            'status' => 'error',
            'code' => $result['statusCode'] ?? 500,
            'message' => "Gagal membuka pintu: " . ($result['error'] ?? 'Device unreachable'),
        ], 500);
    }

    $doorName = $door->door_name ?? $door->name;

    // UPDATE: Include reason in audit log
    ActivityLog::create([
        'admin_id' => $request->user()->id ?? null,
        'action' => 'remote_door_opened',
        'subject_type' => 'Door',
        'subject_id' => $door->id,
        'description' => "Remote unlock triggered for {$door->door_id} ({$doorName}) via web dashboard. Reason: {$reason}",
        'timestamp' => now(),
    ]);

    return response()->json([
        'status' => 'success',
        'message' => "Pintu {$doorName} ({$door->door_id}) berhasil dibuka via remote.",
        'data' => new DoorResource($door->fresh()),
    ], 200);
}
```

## API Contract Update

**Request Body (MUST include reason):**

```json
{
  "reason": "Emergency server access required - cooling issue detected"
}
```

**Error Responses:**

```json
{
  "status": "error",
  "message": "Reason cannot be empty or whitespace-only.",
  "errors": {
    "reason": ["The reason field is required.", "The reason must be at least 10 characters."]
  }
}
```

## UI Validation

Frontend already requires reason (textarea, modal validation).

Backend validation is **server-side enforcement**, not UI-only.

## Testing

```php
// tests/Feature/RemoteUnlockReasonTest.php
public function test_remote_unlock_requires_reason()
{
    $door = Door::factory()->create(['connection_status' => 'online']);
    $admin = Admin::factory()->create();
    
    // No reason → 422
    $response = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/doors/' . $door->id . '/open', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.reason', ['The reason field is required.']);
    
    // Whitespace-only → 422
    $response = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/doors/' . $door->id . '/open', ['reason' => '   '])
        ->assertStatus(422);
    
    // Valid reason → 200
    $response = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/doors/' . $door->id . '/open', [
            'reason' => 'Emergency server room access - fire alarm test'
        ])
        ->assertStatus(200);
    
    // Verify audit log contains reason
    $log = ActivityLog::where('action', 'remote_door_opened')->latest()->first();
    $this->assertStringContainsString('Emergency server room access', $log->description);
}

public function test_reason_too_short()
{
    // < 10 chars rejected
    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/doors/' . $door->id . '/open', ['reason' => 'Fix'])
        ->assertStatus(422);
}

public function test_reason_too_long()
{
    // > 500 chars rejected
    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/doors/' . $door->id . '/open', [
            'reason' => str_repeat('x', 501)
        ])
        ->assertStatus(422);
}
```

## Audit Trail Example

**Before (Current):**
```
description: "Remote unlock triggered for DOOR-B (Server Room) via web dashboard."
```

**After (With Reason):**
```
description: "Remote unlock triggered for DOOR-B (Server Room) via web dashboard. Reason: Fire safety drill - testing access protocol"
```

## Implementation Checklist

- [ ] Add validation in AdminDoorController::openDoor()
- [ ] Persist reason in ActivityLog description
- [ ] Update API documentation (OpenAPI/Swagger)
- [ ] Update frontend to submit reason with request
- [ ] Add test cases for validation
- [ ] Test edge cases (whitespace, too long, etc.)
- [ ] Code review + approval
- [ ] No migration needed (reason goes in ActivityLog, not separate table)

## Timeline

- Implementation: ~30 minutes
- Testing: ~30 minutes
- Review: Required before merge
- Blocks: None (can be done in parallel with door/1 fix)

## Security Benefit

- Complete audit trail with administrative justification
- Traceable unauthorized access attempts
- Compliance with access control logging standards
