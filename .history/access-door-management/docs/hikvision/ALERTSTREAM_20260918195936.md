# SOP: ALERTSTREAM — REAL-TIME EVENT DELIVERY

**PURPOSE**: Define how the application subscribes to and processes the Hikvision AlertStream event feed.  
**SCOPE**: `pkp_securegate_alertstream_door_b` container and `HikvisionIsapiService::streamEvents()`.  
**AUTHORIZATION REQUIRED**: None (read-only subscription).  

---

## PREREQUISITES

- Device reachable at 192.168.90.15:80
- `pkp_securegate_alertstream_door_b` container running
- `HIKVISION_MOCK=false` in production environment
- Prometheus metrics endpoint available for health monitoring

---

## What AlertStream Is

AlertStream is a **persistent HTTP GET** to `/ISAPI/Event/notification/alertStream`.

- Device keeps connection open and pushes events as they occur
- Transport: HTTP/1.1, `Transfer-Encoding: chunked`
- Content-Type: `multipart/mixed; boundary=<boundary>`
- Each event = one MIME part containing JSON or XML event body

**The application is the subscriber; the device is the publisher.**

---

## Freshness Gate (CRITICAL)

On reconnect, the device **replays buffered events** (events that occurred while disconnected).

These buffered events MUST be discarded if they are older than `ALERTSTREAM_FRESHNESS_THRESHOLD`.

```php
// Default threshold: 30 seconds
if (Carbon::parse($event['dateTime'])->diffInSeconds(now()) > config('securegate.freshness_threshold', 30)) {
    // DISCARD — stale replay event
    Log::info('alertstream.stale', ['serialNo' => $event['serialNo'], 'age_seconds' => ...]);
    return;
}
```

**Consequence of missing freshness gate**: Access denial events from 10 minutes ago get re-processed and logged as current, corrupting attendance/access records.

---

## Event Processing Pipeline

```
Device pushes event chunk
    → Parse multipart boundary
    → Extract MIME part body
    → json_decode / xml_decode
    → Extract serialNo, dateTime, eventType, cardNo, employeeNo
    → FRESHNESS CHECK: age > threshold? → discard
    → DEDUP CHECK: serialNo already in access_logs? → discard
    → Map employeeNo → employee_id (mapping_coverage check)
    → Insert access_log record
    → Emit Prometheus counter (securegate_event_ingestion_total)
```

---

## Reconnect Behavior

| Scenario | Behavior |
|---|---|
| Clean disconnect | Container restarts, reconnects, applies freshness gate |
| Network interruption | TCP timeout → reconnect with backoff (max 60s) |
| Device reboot | Stream drops → reconnect after device returns |
| Container crash | Docker restart policy restarts container |

**Backoff**: Reconnect with exponential backoff starting at 5s, max 60s. Log each attempt.

**Do not suppress reconnect attempts** — loss of AlertStream means loss of real-time access events.

---

## Deduplication

Each device event has a `serialNo` field. The application uses this as the deduplication key.

```php
// Before inserting access_log
if (AccessLog::where('serial_no', $event['serialNo'])->exists()) {
    // Duplicate — skip silently
    return;
}
```

This prevents double-inserts when:
- AlertStream replays on reconnect within freshness window
- AcsEvent backfill and AlertStream deliver same event

---

## Health Monitoring

Monitor via Prometheus metric: `securegate_event_ingestion_total`

| Label | Meaning |
|---|---|
| `status="processed"` | Events successfully ingested |
| `status="ignored"` | Events discarded (stale/dup/no-map) |
| `status="error"` | Parse or insert failures |

Also monitor:
- Container status: `docker inspect pkp_securegate_alertstream_door_b` — State.Status should be `running`
- Stream listener count: available via `/metrics/json` → `listener_count` field

**Alert threshold**: If `listener_count=0` for > 2 minutes, trigger `DEVICE_OFFLINE.md` or container restart SOP.

---

## PRE-CHECK (before starting stream)

```bash
# Verify device reachable
curl -u "admin:$ISAPI_PASS" --digest "http://192.168.90.15:80/ISAPI/System/time" -s -o /dev/null -w "%{http_code}"
# Expected: 200

# Verify container running
docker inspect pkp_securegate_alertstream_door_b --format '{{.State.Status}}'
# Expected: running
```

---

## PROCEDURE — Restart AlertStream (if stopped)

1. Verify device online (ping + ISAPI check above)
2. Check why container stopped: `docker logs pkp_securegate_alertstream_door_b --tail=50`
3. Start container: `docker compose -f docker-compose.prod.yml up -d alertstream_door_b`
4. Wait 10 seconds
5. Verify listener: check `/metrics/json` for `listener_count=1`
6. Verify events flowing: `securegate_event_ingestion_total` should increment on next card swipe

---

## VALIDATION

After restart:
- [ ] Container status = running
- [ ] `listener_count` = 1
- [ ] `securegate_event_ingestion_total{status="processed"}` increments on test card swipe
- [ ] No `status="error"` increment

---

## STOP CONDITIONS

Stop and escalate if:
- Container restarts more than 3 times in 10 minutes (restart loop)
- `status="error"` metric exceeds `status="processed"` over 5-minute window
- Device is unreachable (separate `DEVICE_OFFLINE.md` SOP)

---

## AUDIT EVIDENCE

Log on each stream session start:
- Container start timestamp
- Device IP and door ID
- First event serialNo received
- Freshness gate threshold in use

Log on each discard:
- Reason (stale/dup/no-map)
- serialNo
- Event age in seconds (if stale)
