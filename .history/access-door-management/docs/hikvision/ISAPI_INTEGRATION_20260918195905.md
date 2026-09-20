# SOP: ISAPI INTEGRATION

**PURPOSE**: Define how the application communicates with Hikvision devices via ISAPI.  
**SCOPE**: All server-to-device ISAPI operations.  
**AUTHORIZATION REQUIRED**: Read operations — none. Write operations — see `AUTHORIZATION REQUIRED` sections in write SOPs.  

---

## PREREQUISITES

- Device reachable on LAN (ping 192.168.90.15)
- `doors` table row for target door has `isapi_host`, `isapi_port`, `isapi_username`, `isapi_password`
- `HIKVISION_MOCK=false` in environment (or `services.hikvision.mock=false` in config)
- `HikvisionIsapiService` loaded via Laravel service container

---

## HTTP Digest Authentication

Hikvision ISAPI uses HTTP Digest Authentication (RFC 7616), **not Basic Auth**.

```php
// HikvisionIsapiService::buildHttpClient()
Http::withDigestAuth($username, $password)
    ->baseUrl("http://{$door->isapi_host}:{$door->isapi_port}")
    ->timeout(10)
    ->withHeaders(['Accept' => 'application/json'])
```

**Never use `withBasicAuth()`** — device will reject with 401 and the error message will not indicate the auth method.

---

## URL Construction

```php
// buildUrl() in HikvisionIsapiService
"/ISAPI/{$path}"  // for ISAPI namespace
"/ISAPI/AccessControl/{$path}"  // for access control endpoints
```

Most access control endpoints are under `/ISAPI/AccessControl/`.

---

## JSON vs XML Responses

**Critical**: Device always prepends `<?xml version="1.0" encoding="UTF-8"?>` even to JSON responses.

```php
// Strip XML header before json_decode
$body = trim(preg_replace('/<\?xml[^?]+\?>/', '', $response->body()));
$data = json_decode($body, true);
```

Without stripping, `json_decode()` returns `null` and silently fails.

Force JSON format by appending `?format=json` to POST search endpoints:
```
/ISAPI/AccessControl/UserInfo/Search?format=json
/ISAPI/AccessControl/AcsEvent?format=json
```

---

## Key Endpoints

### Device Information
```
GET /ISAPI/System/deviceInfo
GET /ISAPI/System/capabilities
GET /ISAPI/System/time
GET /ISAPI/System/time/ntpServers
```

### User Management
```
POST /ISAPI/AccessControl/UserInfo/Search?format=json   (paginated list)
PUT  /ISAPI/AccessControl/UserInfo/Record               (create/update user)
DELETE /ISAPI/AccessControl/UserInfo/Delete             (remove user)
```

### Card Management
```
POST /ISAPI/AccessControl/CardInfo/Search?format=json   (paginated list)
PUT  /ISAPI/AccessControl/CardInfo/Record               (assign card)
DELETE /ISAPI/AccessControl/CardInfo/Delete             (remove card)
```

### Access Control State
```
GET /ISAPI/AccessControl/AcsWorkStatus   (door/lock/sensor/alarm state)
GET /ISAPI/AccessControl/CardReaderCfg/1 (reader 1 config, fingerprint counts)
GET /ISAPI/AccessControl/CardReaderCfg/2 (reader 2 config, RS-485/Wiegand)
```

### Events
```
POST /ISAPI/AccessControl/AcsEvent?format=json   (historical event search)
GET  /ISAPI/Event/notification/alertStream        (real-time stream)
```

---

## Pagination Pattern (UserInfo/Search, CardInfo/Search)

```php
$allItems = [];
$pos = 0;
while (true) {
    $response = $client->post($url, [
        'UserInfoSearchCond' => [
            'searchID'               => 'search-' . uniqid(),
            'searchResultPosition'   => $pos,
            'maxResults'             => 30,
        ]
    ]);
    $body = trim(preg_replace('/<\?xml[^?]+\?>/', '', $response->body()));
    $data = json_decode($body, true);

    $batch = $data['UserInfoSearch']['UserInfo'] ?? [];
    if (empty($batch)) break;

    $allItems = array_merge($allItems, $batch);
    $pos += count($batch);

    if ($pos >= ($data['UserInfoSearch']['totalMatches'] ?? 0)) break;
}
```

**Max results per request**: 30 (device limit). Do not request more.  
**searchID**: Must be unique per search session, not per page.

---

## Error Handling

| HTTP Status | Meaning | Action |
|---|---|---|
| 200 | Success | Parse body |
| 400 | Bad request (wrong params) | Log request params, do not retry blindly |
| 401 | Auth failed | Check credentials in `doors` table; do not lock out device |
| 404 | Endpoint not supported | Mark capability as NOT_SUPPORTED; log |
| 500 | Device internal error | Log; retry with backoff; alert if persistent |
| Connection refused / timeout | Device offline | Trigger offline SOP (`DEVICE_OFFLINE.md`) |

**Do not retry 401 automatically** — repeated failed auth may trigger device lockout.

---

## Mock Mode

When `services.hikvision.mock=true`:
- All ISAPI calls return synthetic fixture data
- Used in CI test suite (all 489 tests pass in mock mode)
- Never enable mock mode in production

```php
// config/services.php
'hikvision' => [
    'mock' => env('HIKVISION_MOCK', false),
]
```

---

## STOP CONDITIONS

Stop and escalate if:
- Three consecutive 401 responses from same device (credential rotation required)
- Device returns 500 with `deviceBusy` in body (do not queue more requests)
- Any write operation returns unexpected response body (stop, do not retry)

---

## AUDIT EVIDENCE

All ISAPI interactions should be logged with:
- Timestamp
- Door ID
- Endpoint called
- HTTP method
- Response status
- Whether it was a read or write operation

Write operations additionally require:
- Initiating admin ID
- Pre-write state (backup)
- Post-write validation response
