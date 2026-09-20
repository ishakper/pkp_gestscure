# SOP: PASSWORD ROTATION

**PURPOSE**: Define the procedure for rotating device administrator passwords safely.  
**SCOPE**: `doors.isapi_password` field, device ISAPI admin user password.  
**AUTHORIZATION REQUIRED**: Mandatory — infrastructure lead approval.  

---

## The Risk of Broken Rotation

A botched password rotation locks out the application, kills AlertStream, and halts all access control operations.

The procedure uses a **5-step workflow** to guarantee zero downtime:
```
TEST CURRENT → STAGE NEW → APPLY TO DEVICE → TEST NEW ON DEVICE → PERSIST TO DB
```

If any step fails before persist: **rollback is trivial** (revert to known working state).

---

## Password Policy for Devices

- Length: minimum 12 characters (Hikvision limit: 16 characters maximum on some firmware)
- Complexity: uppercase + lowercase + numbers + special characters (avoid `&`, `<`, `>`, `"`, `'` due to XML/JSON escaping issues)
- Generated cryptographically: `bin2hex(random_bytes(8))` or similar
- Stored: encrypted in `doors.isapi_password` via Laravel's encryption (`Crypt::encryptString`)

---

## 5-Step Rotation Procedure

### Step 1: TEST CURRENT CREDENTIALS

Verify current credentials work before touching anything:

```php
$svc = app(App\Services\HikvisionIsapiService::class);
$door = App\Models\Door::where('door_id', 'DOOR-B')->first();
$ping = $svc->pingDevice($door);
// MUST return ['success' => true]
```

If test fails: **STOP**. Do not proceed with rotation if current password is unknown.

### Step 2: STAGE NEW PASSWORD

Generate and hold in memory — do NOT write to DB yet:

```php
$newPassword = generateStrongPassword(14); // 12-16 chars, no problematic XML chars
```

### Step 3: APPLY TO DEVICE VIA ISAPI

Update device administrator password:

```http
PUT /ISAPI/Security/users/1
Content-Type: application/json

{
    "User": {
        "id": 1,
        "userName": "admin",
        "password": "<newPassword>"
    }
}
```

Authenticate this request with **current (old)** password via HTTP Digest.

### Step 4: TEST NEW CREDENTIALS ON DEVICE

Verify device accepted the new password:

```php
// Test with new password
$client = Http::withDigestAuth('admin', $newPassword)
    ->baseUrl("http://{$door->isapi_host}:{$door->isapi_port}")
    ->get('/ISAPI/System/time');

// MUST return 200
```

If this test fails:
- Device rejected change or password got corrupted
- Re-test with OLD password
- If old works: abort, no harm done
- If neither works: physical access to terminal required (on-site admin menu)

### Step 5: PERSIST TO APPLICATION DB

Only after Step 4 passes:

```php
$door->isapi_password = $newPassword; // Cast handles encryption
$door->save();
```

Restart AlertStream container to pick up new password:
```bash
docker compose -f docker-compose.prod.yml restart alertstream_door_b
```

Verify AlertStream connects: check `/metrics/json` for `listener_count=1`.

---

## Rollback Procedure

If Step 3 succeeds on device but Step 4 fails:
1. Re-attempt PUT to `/ISAPI/Security/users/1` using whatever credential works
2. If locked out: use device physical interface (requires physical key/access)
3. Reset admin password via device local menu
4. Update application DB with that password

---

## Emergency Access Note

Hikvision devices have a physical "Reset" button on the circuit board.  
Pressing for 10 seconds resets network and passwords to factory defaults.  
> ⚠️ This ALSO wipes user data on some firmware versions.  
> **Never use hardware reset button without confirming data retention.**  
> See `FACTORY_RESET_RECOVERY.md`.

---

## AUDIT EVIDENCE

Log for every password rotation:
- Timestamp of rotation start and completion
- Door ID
- Initiating admin
- Verification test result (HTTP 200 confirmation)
- AlertStream reconnect confirmation
- Ticket/change request reference

**Never log the password itself** — not even in masked form.
