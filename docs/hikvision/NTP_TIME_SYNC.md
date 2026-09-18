# SOP: NTP TIME SYNCHRONIZATION

**PURPOSE**: Document current clock state, NTP configuration gap, and procedure to fix it.  
**SCOPE**: Device clock, `/ISAPI/System/time`, `/ISAPI/System/time/ntpServers`.  
**AUTHORIZATION REQUIRED**: NTP configuration write requires infrastructure lead approval.  

---

## Current State (2026-09-18) — WARNING

| Field | Value | Status |
|---|---|---|
| Device time | 2026-09-18T18:28:30+07:00 | — |
| Server time (at probe) | 2026-09-18T18:28:42+07:00 | — |
| Clock offset | +72 seconds (device fast) | ⚠️ WARNING |
| Time mode | `manual` | ❌ BAD |
| NTP hostname | `""` (empty) | ❌ NOT CONFIGURED |
| NTP port | 123 | ✅ Correct port |

**The device clock is 72 seconds ahead of the application server.** NTP is not configured.

---

## Clock Offset Severity Thresholds

| Offset | Severity | Impact |
|---|---|---|
| < 30 seconds | OK | Negligible |
| 30–120 seconds | WARNING | Event timestamps off by up to 2 min; access schedule boundary effects |
| > 120 seconds | CRITICAL | Access schedule may grant/deny access at wrong times |

At +72s: access events are timestamped ~72 seconds into the future relative to application server.

---

## Impact of No NTP

1. **Timestamp inaccuracy**: Access log `dateTime` from device is 72s ahead of reality
2. **Schedule boundary errors**: If access is permitted 07:00–18:00, device grants access until 17:58:48 (72s early cutoff) or 18:01:12 (72s late cutoff) depending on drift direction
3. **Audit record integrity**: Timestamped evidence in access_logs may not match CCTV footage time
4. **AlertStream freshness gate**: Freshness comparison uses server time vs device event time — 72s drift may cause fresh events to appear stale

---

## PRE-CHECK (Read-Only)

```php
// Read current device time via ISAPI
php artisan tinker --execute="
    \$svc = app(App\Services\HikvisionIsapiService::class);
    \$d = App\Models\Door::where('door_id', 'DOOR-B')->first();
    \$client = ...buildHttpClient(\$d);
    \$r = \$client->get(\$svc->buildUrl('/System/time', \$d));
    \$body = trim(preg_replace('/<\?xml[^?]+\?>/', '', \$r->body()));
    \$j = json_decode(\$body, true);
    dump(\$j['Time']);
    dump('server_time: ' . now()->toIso8601String());
"
```

---

## PROCEDURE — Configure NTP (WRITE)

> Requires: infrastructure lead approval

1. Identify NTP server to use (company NTP server or pool.ntp.org)
2. Back up device time config: read and record current `/System/time` and `/System/time/ntpServers`
3. Configure NTP server:
```http
PUT /ISAPI/System/time/ntpServers/1
Content-Type: application/json

{
    "NTPServer": {
        "id": 1,
        "addressingFormatType": "hostname",
        "hostName": "pool.ntp.org",
        "portNo": 123,
        "synchronizeInterval": 1440
    }
}
```
4. Set time mode to NTP:
```http
PUT /ISAPI/System/time
{
    "Time": {
        "timeMode": "NTP",
        "timeZone": "CST-7:00:00"
    }
}
```
5. Verify: re-read `/System/time` — `timeMode` should now be `NTP`
6. Wait 2 minutes, re-read device time, compare to server time
7. Expected: offset < 5 seconds

---

## PROCEDURE — Monitor Clock Drift

Schedule periodic check (daily minimum):

```bash
php artisan tinker --execute="
    // ... read device time, compare to now(), log offset
"
```

Alert if offset exceeds 30 seconds.

---

## PROCEDURE — Manual Time Set (Fallback, if NTP unavailable)

> Only if NTP not reachable or not permitted by network policy

```http
PUT /ISAPI/System/time
{
    "Time": {
        "timeMode": "manual",
        "localTime": "2026-09-18T18:30:00",
        "timeZone": "CST-7:00:00"
    }
}
```

Manual time requires re-sync periodically. Not recommended.

---

## VALIDATION

After NTP configuration:
- [ ] Device `timeMode = NTP`
- [ ] Device `hostName` set to chosen NTP server
- [ ] Clock offset < 5 seconds after 2-minute sync
- [ ] AlertStream freshness gate still functioning correctly
- [ ] Access schedule boundary test: card swipe at exactly schedule start time produces expected result

---

## STOP CONDITIONS

Stop and revert if:
- Device rejects NTP configuration PUT (returns 4xx/5xx)
- After NTP sync, clock jumps more than 10 minutes (would disrupt event log continuity)
- AlertStream freshness gate starts discarding fresh events after sync (re-calibrate threshold)

---

## AUDIT EVIDENCE

- Pre-configuration clock offset measurement
- NTP server configured
- Post-configuration clock verification
- Timestamp of change
- Admin who applied change
