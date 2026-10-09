# SOP: DOOR SENSOR — STATE INTERPRETATION

**PURPOSE**: Define how door and lock sensor data is read and interpreted.  
**SCOPE**: `AcsWorkStatus` fields `doorStatus`, `doorLockStatus`, `magneticStatus`.  
**AUTHORIZATION REQUIRED**: None (read-only).  

---

## Sensor Fields in AcsWorkStatus

```
GET /ISAPI/AccessControl/AcsWorkStatus
```

Response fields (parsed from JSON body after stripping XML header):

| Field | Type | Values |
|---|---|---|
| `doorStatus` | int array | 0=closed, 1=open, 4=UNKNOWN |
| `doorLockStatus` | int array | 0=locked, 1=unlocked |
| `magneticStatus` | int array | 0=closed (contact), 1=open (no contact) |
| `powerSupplyStatus` | string | `"ACPowerSupply"`, `"battery"` |
| `hostAntiDismantleStatus` | string | `"close"` (not tampered), `"open"` (tampered) |

---

## doorStatus Code Reference

| Code | Meaning | Reliability |
|---|---|---|
| 0 | Door closed (confirmed by contact sensor) | High |
| 1 | Door open | High |
| 4 | UNKNOWN — contact sensor state not deterministic | Low |

**UNKNOWN (code 4) explanation**: The door contact sensor is present (`magneticStatus` field responds) but `doorStatus` aggregation returns code 4 when the controller cannot definitively confirm open or closed state. This may occur when:
- Sensor reading is transitional
- Sensor wiring is marginal
- Device firmware cannot correlate relay state with sensor state

**Resolution**: When `doorStatus=4`, use `magneticStatus` as fallback:
- `magneticStatus=0` → magnetic contact closed → door physically closed
- `magneticStatus=1` → magnetic contact open → door physically open

---

## Current State (2026-09-18)

| Field | Value | Interpretation |
|---|---|---|
| `doorStatus` | 4 | UNKNOWN — do not use directly |
| `doorLockStatus` | 0 | Locked |
| `magneticStatus` | 0 | Magnetic contact closed = door closed |
| `powerSupplyStatus` | ACPowerSupply | Mains power, no battery failover |
| `hostAntiDismantleStatus` | close | Not tampered |

**Conclusion**: Door is CLOSED and LOCKED. `doorStatus=4` is a sensor aggregation artifact.

---

## Application Door State Logic

```php
// In HikvisionIsapiService or DoorStateService
public function getDoorState(array $acsWorkStatus): string
{
    $lockStatus  = $acsWorkStatus['doorLockStatus'][0] ?? null;
    $magStatus   = $acsWorkStatus['magneticStatus'][0] ?? null;
    $doorStatus  = $acsWorkStatus['doorStatus'][0] ?? null;

    if ($doorStatus === 4 || $doorStatus === null) {
        // Fallback: trust magnetic sensor
        return $magStatus === 0 ? 'closed' : ($magStatus === 1 ? 'open' : 'unknown');
    }

    return match ($doorStatus) {
        0 => 'closed',
        1 => 'open',
        default => 'unknown',
    };
}
```

---

## Tamper Detection

| Condition | Field | Action |
|---|---|---|
| Device tamper | `hostAntiDismantleStatus = "open"` | Trigger INCIDENT_RESPONSE.md |
| Reader tamper | `cardReaderAntiDismantleStatus` not empty | Trigger INCIDENT_RESPONSE.md |
| Alarm input active | `alarmInStatus` not empty | Log and alert |
| Alarm output triggered | `alarmOutStatus` not empty | Log current state |

---

## Alert Thresholds

| Event | Threshold | Action |
|---|---|---|
| Door open > 30s (magnetic=1) | 30 seconds | Alert security team |
| Door locked but magnetic=1 | Immediate | Investigate: door may not be latching |
| hostAntiDismantleStatus=open | Immediate | Incident response |
| doorLockStatus=1 unexpectedly | Immediate | Verify no access granted; alert |

---

## PRE-CHECK (Routine Health)

```bash
php artisan tinker --execute="
    \$svc = app(App\Services\HikvisionIsapiService::class);
    \$d = App\Models\Door::where('door_id', 'DOOR-B')->first();
    \$r = \$svc->getDeviceStatus(\$d);
    dump(\$r['data']);
"
```

Expected: `door_status='closed'`, `online=true`.

---

## AUDIT EVIDENCE

Poll `AcsWorkStatus` at least every 5 minutes in production. Store:
- Timestamp
- doorLockStatus
- magneticStatus
- doorStatus (raw code)
- Interpreted state (using fallback logic above)
- powerSupplyStatus
- hostAntiDismantleStatus
