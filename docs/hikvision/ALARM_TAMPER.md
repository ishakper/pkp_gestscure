# SOP: ALARM AND TAMPER

**PURPOSE**: Define how device alarms and tamper events are detected, classified, and handled.  
**SCOPE**: `AcsWorkStatus` alarm fields, AlertStream alarm events.  
**AUTHORIZATION REQUIRED**: Investigation — security team. Physical response — facilities + security.  

---

## Alarm Fields in AcsWorkStatus

```
GET /ISAPI/AccessControl/AcsWorkStatus
```

| Field | Type | Meaning |
|---|---|---|
| `alarmInStatus` | int array | Active alarm inputs (indices of triggered inputs) |
| `alarmOutStatus` | int array | Active alarm outputs |
| `hostAntiDismantleStatus` | string | `"close"` = not tampered, `"open"` = tampered |
| `cardReaderAntiDismantleStatus` | int array | Indices of tampered card readers |

---

## Current State (2026-09-18)

| Field | Value | Status |
|---|---|---|
| `alarmInStatus` | `[]` | No alarms active |
| `alarmOutStatus` | `[]` | No outputs triggered |
| `hostAntiDismantleStatus` | `"close"` | Not tampered |
| `cardReaderAntiDismantleStatus` | `[]` | Reader 1 not tampered |

---

## Alarm Classification

### Severity Levels

| Level | Condition | Response Time |
|---|---|---|
| CRITICAL | `hostAntiDismantleStatus="open"` (device physically opened/removed) | Immediate (< 5 min) |
| CRITICAL | `cardReaderAntiDismantleStatus` not empty (reader removed) | Immediate (< 5 min) |
| HIGH | `alarmInStatus` not empty (connected alarm sensor triggered) | < 15 min |
| HIGH | Door forced open (AlertStream event: eventType="doorOpenTimeout") | < 15 min |
| MEDIUM | Door open timeout (magneticStatus=1, doorLockStatus=0, > 30s) | < 30 min |
| LOW | Repeated card denial (> 5 failures in 2 min, same card) | < 2 hours |

---

## AlertStream Alarm Events

In addition to `AcsWorkStatus` polling, AlertStream delivers alarm events in real-time:

| Event Type | Meaning |
|---|---|
| `antipassbackAlarm` | (N/A — APB not supported) |
| `doorOpenTimeout` | Door held open past threshold |
| `accessControllerAlarm` | General device alarm |
| `tamperAlarm` | Device or reader tampered |
| `intrusionAlarm` | Alarm input triggered |

These events appear in `access_logs.event_type` field.

---

## PROCEDURE — Tamper Response

> This is an INCIDENT — follow `INCIDENT_RESPONSE.md` for full workflow.

1. **Detect**: `hostAntiDismantleStatus="open"` in AcsWorkStatus poll or AlertStream tamperAlarm event
2. **Alert**: Immediately notify security team and facilities
3. **Do not clear tamper remotely** — physical investigation required first
4. **Dispatch**: Send security personnel to device location
5. **Assess**: Is device still mounted? Is cover intact? Is card reader in place?
6. **If unauthorized access detected**: Treat as physical security incident
   - Preserve evidence (do not power down device — logs may be lost)
   - Contact building security management
7. **If false alarm** (device cover accidentally opened during maintenance):
   - Resecure device
   - Verify `hostAntiDismantleStatus` returns to `"close"`
   - Log in `activity_logs` with explanation and authorizer name
8. **Report**: Document in `INCIDENT_RESPONSE.md` format

---

## PROCEDURE — Door Forced Open

1. Detect: AlertStream `doorOpenTimeout` event or `magneticStatus=1` while `doorLockStatus=0` unexpectedly
2. Alert security team
3. Review AlertStream events: find last card swipe at DOOR-B
4. Cross-reference with CCTV if available
5. If unauthorized: escalate
6. If authorized but door held: remind employee to close door promptly

---

## STOP CONDITIONS

Never auto-clear a tamper alarm via ISAPI before physical inspection. Remote clearing masks the incident.

---

## AUDIT EVIDENCE

Log every alarm event:
- Event type and severity
- Detection method (poll vs AlertStream)
- Timestamp
- Response taken
- Resolution timestamp
- Initiating responder name
